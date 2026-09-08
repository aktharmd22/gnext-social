<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppCredential;
use App\Services\ActivityLogger;
use App\Services\Meta\GraphException;
use App\Services\Meta\MetaOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The Facebook connect flow.
 *
 * Ends by parking the discovered Pages in the session rather than connecting
 * them all: the operator chooses which Pages this workspace publishes to, and
 * connecting every Page someone happens to administer would be presumptuous and
 * occasionally embarrassing.
 */
class FacebookOAuthController extends Controller
{
    private const STATE_KEY = 'meta.oauth.state';

    private const PAGES_KEY = 'meta.oauth.pages';

    private const EXPIRY_KEY = 'meta.oauth.expires_at';

    public function redirect(Request $request): RedirectResponse
    {
        Gate::authorize('manage-accounts');

        $credential = $this->credential($request);

        if ($credential === null || ! $credential->isUsable()) {
            return redirect()
                ->route('settings.meta-app')
                ->with('error', 'Add your Meta App ID and secret before connecting a Page.');
        }

        // CSRF for the round trip through Facebook.
        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);

        return redirect()->away((new MetaOAuthService($credential))->authorizationUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        Gate::authorize('manage-accounts');

        if ($request->filled('error')) {
            return redirect()->route('settings.accounts')->with(
                'error',
                $request->string('error_description')->value()
                    ?: 'Facebook cancelled the connection.'
            );
        }

        $expected = $request->session()->pull(self::STATE_KEY);

        if ($expected === null || ! hash_equals((string) $expected, (string) $request->query('state'))) {
            return redirect()->route('settings.accounts')->with(
                'error',
                'That connection attempt expired. Start it again from this page.'
            );
        }

        $credential = $this->credential($request);

        if ($credential === null) {
            return redirect()->route('settings.meta-app')->with(
                'error',
                'No Meta app is configured for this workspace.'
            );
        }

        $oauth = new MetaOAuthService($credential);

        try {
            $shortLived = $oauth->exchangeCode((string) $request->query('code'));
            $longLived = $oauth->exchangeForLongLivedToken($shortLived);
            $pages = $oauth->pages($longLived['token']);
        } catch (GraphException $exception) {
            return redirect()->route('settings.accounts')->with(
                'error',
                $exception->userMessage()
            );
        }

        if ($pages->isEmpty()) {
            return redirect()->route('settings.accounts')->with(
                'error',
                'That account does not manage any Facebook Pages. '
                    .'Connect with an account that has a Page role, and make sure you granted access to it.'
            );
        }

        // Page access tokens are the credential; they must not sit in a session
        // longer than the selection step.
        $request->session()->put(self::PAGES_KEY, $pages->all());
        $request->session()->put(self::EXPIRY_KEY, $longLived['expires_at']?->toIso8601String());

        app(ActivityLogger::class)->log('meta.oauth.completed', $credential, [
            'pages_discovered' => $pages->count(),
        ]);

        return redirect()->route('settings.accounts');
    }

    private function credential(Request $request): ?AppCredential
    {
        return AppCredential::query()
            ->where('workspace_id', $request->user()->workspace_id)
            ->first();
    }
}
