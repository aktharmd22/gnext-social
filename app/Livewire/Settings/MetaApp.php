<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\AppCredential;
use App\Services\ActivityLogger;
use App\Services\Meta\MetaOAuthService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Settings > Meta app.
 *
 * Both roles may configure the API. Neither may read the stored secret: it is
 * write-only in this form, shown as a mask with a "Replace secret" action, and
 * it is never sent to the browser at all.
 */
class MetaApp extends Component
{
    #[Validate('required|string|max:64')]
    public string $metaAppId = '';

    #[Validate('required|string|max:16')]
    public string $graphVersion = 'v21.0';

    #[Validate('required|url|max:512')]
    public string $redirectUri = '';

    /**
     * Only ever holds a NEW secret being typed. The stored one is never loaded
     * into this property, so it cannot reach the rendered HTML or a Livewire
     * payload.
     */
    public string $newSecret = '';

    public bool $replacingSecret = false;

    public bool $hasStoredSecret = false;

    /** @var null|array<string, mixed> */
    public ?array $testResult = null;

    public function mount(): void
    {
        Gate::authorize('manage-credentials');

        $credential = $this->credential();

        $this->metaAppId = (string) ($credential?->meta_app_id ?? '');
        $this->graphVersion = (string) ($credential?->graph_version ?? 'v21.0');
        $this->redirectUri = (string) ($credential?->redirect_uri ?? route('oauth.facebook.callback'));
        $this->hasStoredSecret = filled($credential?->meta_app_secret);

        // A first-time setup has nothing to replace, so the field is open.
        $this->replacingSecret = ! $this->hasStoredSecret;
    }

    public function save(ActivityLogger $log): void
    {
        Gate::authorize('manage-credentials');

        $this->validate([
            'metaAppId' => ['required', 'string', 'max:64', 'regex:/^\d+$/'],
            'graphVersion' => ['required', 'string', 'max:16', 'regex:/^v\d+\.\d+$/'],
            'redirectUri' => ['required', 'url', 'max:512'],
            'newSecret' => [
                Rule::requiredIf(fn () => ! $this->hasStoredSecret),
                'nullable',
                'string',
                'min:16',
                'max:255',
            ],
        ], [
            'metaAppId.regex' => 'A Meta App ID is all digits. Copy it from the top of your app dashboard.',
            'graphVersion.regex' => 'Use a version like v21.0.',
            'newSecret.min' => 'That looks too short for an app secret. Copy the whole value.',
            'newSecret.required' => 'Add your app secret to finish setting this up.',
        ]);

        $credential = $this->credential() ?? new AppCredential([
            'workspace_id' => auth()->user()->workspace_id,
        ]);

        $credential->fill([
            'workspace_id' => auth()->user()->workspace_id,
            'meta_app_id' => $this->metaAppId,
            'graph_version' => $this->graphVersion,
            'redirect_uri' => $this->redirectUri,
            'updated_by' => auth()->id(),
        ]);

        if ($this->newSecret !== '') {
            $credential->meta_app_secret = $this->newSecret;

            // Replacing the secret invalidates any previous verification.
            $credential->is_verified = false;
            $credential->verified_at = null;
        }

        $credential->save();

        $log->logChanges('meta_app.updated', $credential);

        $this->newSecret = '';
        $this->hasStoredSecret = true;
        $this->replacingSecret = false;
        $this->testResult = null;

        $this->dispatch('toast', message: 'Meta app settings saved.');
    }

    /**
     * Calls debug_token and reports what came back in plain language: whether
     * the app is reachable, which scopes were granted, and when it expires.
     */
    public function testConnection(): void
    {
        Gate::authorize('manage-credentials');

        $credential = $this->credential();

        if ($credential === null || ! $credential->isUsable()) {
            $this->testResult = [
                'ok' => false,
                'headline' => 'Save your App ID and secret first.',
                'detail' => 'There is nothing to test until both are stored.',
            ];

            return;
        }

        $oauth = new MetaOAuthService($credential);

        // The app token proves the App ID and secret agree with each other,
        // which is the thing most often mistyped.
        $result = $oauth->debugToken($credential->meta_app_id.'|'.$credential->meta_app_secret);

        if (! $result['valid']) {
            $this->testResult = [
                'ok' => false,
                'headline' => 'Meta rejected these credentials.',
                'detail' => $result['message']
                    ?? 'Check the App ID and secret are from the same app, and that the app is not in a restricted state.',
            ];

            $credential->forceFill(['is_verified' => false, 'verified_at' => null])->save();

            return;
        }

        $missing = array_values(array_diff(
            (array) config('gnext.meta.scopes'),
            $result['scopes']
        ));

        $credential->forceFill([
            'is_verified' => true,
            'verified_at' => now(),
        ])->save();

        $this->testResult = [
            'ok' => true,
            'headline' => 'Connected to Meta.',
            'detail' => $result['scopes'] === []
                ? 'The app credentials are valid. Scopes are granted per connected Page, so this list fills in once you connect one.'
                : 'Granted: '.implode(', ', $result['scopes']).'.',
            'missing' => $missing,
            'expires' => $result['expires_at']?->timezone(auth()->user()->displayTimezone())->format('j M Y, H:i'),
        ];
    }

    public function startReplacingSecret(): void
    {
        $this->replacingSecret = true;
        $this->newSecret = '';
    }

    public function cancelReplacingSecret(): void
    {
        $this->replacingSecret = false;
        $this->newSecret = '';
        $this->resetValidation('newSecret');
    }

    private function credential(): ?AppCredential
    {
        return AppCredential::query()
            ->where('workspace_id', auth()->user()->workspace_id)
            ->first();
    }

    public function render()
    {
        return view('livewire.settings.meta-app');
    }
}
