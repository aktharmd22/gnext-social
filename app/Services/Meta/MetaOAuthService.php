<?php

declare(strict_types=1);

namespace App\Services\Meta;

use App\Enums\Platform;
use App\Models\AppCredential;
use App\Models\SocialAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Connecting a Facebook Page, and the Instagram account behind it.
 *
 * The flow, in full:
 *   1. send the operator to Facebook to grant scopes
 *   2. exchange the returned code for a short-lived user token
 *   3. exchange that for a long-lived user token (~60 days)
 *   4. list the Pages that token can manage -- each carries its own Page token,
 *      which does not expire while the user token lives
 *   5. for each Page, discover any linked instagram_business_account
 *
 * Step 5 is why an Instagram account cannot be connected on its own: Instagram
 * publishing is authorised through the Page it is linked to.
 */
class MetaOAuthService
{
    public function __construct(private readonly AppCredential $credential) {}

    /**
     * Where to send the operator to grant access.
     */
    public function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->credential->meta_app_id,
            'redirect_uri' => $this->credential->redirect_uri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => implode(',', (array) config('gnext.meta.scopes')),
        ]);

        return 'https://www.facebook.com/'.$this->credential->graphVersion().'/dialog/oauth?'.$query;
    }

    /**
     * Authorisation code -> short-lived user access token.
     *
     * @throws GraphException
     */
    public function exchangeCode(string $code): string
    {
        $response = $this->client()->get('oauth/access_token', [
            'client_id' => $this->credential->meta_app_id,
            'client_secret' => $this->credential->meta_app_secret,
            'redirect_uri' => $this->credential->redirect_uri,
            'code' => $code,
        ]);

        return (string) ($response['access_token']
            ?? throw new GraphException(GraphError::fromTransport('Meta returned no access token.')));
    }

    /**
     * Short-lived -> long-lived user token, roughly 60 days.
     *
     * Page tokens derived from a long-lived user token do not themselves
     * expire, which is why this exchange is not optional.
     *
     * @return array{token: string, expires_at: ?Carbon}
     *
     * @throws GraphException
     */
    public function exchangeForLongLivedToken(string $shortLivedToken): array
    {
        $response = $this->client()->get('oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->credential->meta_app_id,
            'client_secret' => $this->credential->meta_app_secret,
            'fb_exchange_token' => $shortLivedToken,
        ]);

        $token = (string) ($response['access_token']
            ?? throw new GraphException(GraphError::fromTransport('Meta returned no long-lived token.')));

        $expiresIn = isset($response['expires_in']) ? (int) $response['expires_in'] : null;

        return [
            'token' => $token,
            'expires_at' => $expiresIn !== null && $expiresIn > 0 ? now()->addSeconds($expiresIn) : null,
        ];
    }

    /**
     * Inspect a token: is it valid, what scopes does it carry, when does it die.
     *
     * This is what the "Test connection" button calls, and what the daily token
     * health check uses.
     *
     * @return array{valid: bool, scopes: list<string>, expires_at: ?Carbon, app_id: ?string, type: ?string, message: ?string}
     */
    public function debugToken(string $token): array
    {
        try {
            $response = $this->client()->get('debug_token', [
                'input_token' => $token,
                'access_token' => $this->appToken(),
            ]);
        } catch (GraphException $exception) {
            return [
                'valid' => false,
                'scopes' => [],
                'expires_at' => null,
                'app_id' => null,
                'type' => null,
                'message' => $exception->userMessage(),
            ];
        }

        $data = $response['data'] ?? [];
        $expiresAt = isset($data['expires_at']) && (int) $data['expires_at'] > 0
            ? Carbon::createFromTimestamp((int) $data['expires_at'])
            : null;

        return [
            'valid' => (bool) ($data['is_valid'] ?? false),
            'scopes' => array_values((array) ($data['scopes'] ?? [])),
            'expires_at' => $expiresAt,
            'app_id' => isset($data['app_id']) ? (string) $data['app_id'] : null,
            'type' => isset($data['type']) ? (string) $data['type'] : null,
            'message' => $data['error']['message'] ?? null,
        ];
    }

    /**
     * Pages this user token can publish to, each with its own Page token and
     * any linked Instagram Business account.
     *
     * @return Collection<int, array<string, mixed>>
     *
     * @throws GraphException
     */
    public function pages(string $userToken): Collection
    {
        $response = $this->client()->get('me/accounts', [
            'fields' => 'id,name,username,access_token,picture{url},instagram_business_account{id,username,name,profile_picture_url}',
            'limit' => 100,
        ], $userToken);

        return collect($response['data'] ?? [])->map(fn (array $page) => [
            'page_id' => (string) ($page['id'] ?? ''),
            'name' => (string) ($page['name'] ?? 'Untitled page'),
            'username' => $page['username'] ?? null,
            'access_token' => (string) ($page['access_token'] ?? ''),
            'avatar_url' => $page['picture']['data']['url'] ?? null,
            'instagram' => isset($page['instagram_business_account'])
                ? [
                    'ig_user_id' => (string) $page['instagram_business_account']['id'],
                    'username' => $page['instagram_business_account']['username'] ?? null,
                    'name' => $page['instagram_business_account']['name'] ?? ($page['name'] ?? null),
                    'avatar_url' => $page['instagram_business_account']['profile_picture_url'] ?? null,
                ]
                : null,
        ])->filter(fn (array $page) => $page['page_id'] !== '' && $page['access_token'] !== '')
            ->values();
    }

    /**
     * Persist one Page, and its linked Instagram account when there is one, as
     * separate destinations.
     *
     * @param  array<string, mixed>  $page
     * @return list<SocialAccount>
     */
    public function connectPage(array $page, ?Carbon $expiresAt): array
    {
        $connected = [];

        $connected[] = $this->upsert([
            'workspace_id' => $this->credential->workspace_id,
            'platform' => Platform::Facebook,
            'page_id' => $page['page_id'],
            'ig_user_id' => null,
        ], [
            'name' => $page['name'],
            'username' => $page['username'] ?? null,
            'avatar_url' => $page['avatar_url'] ?? null,
            'access_token' => $page['access_token'],
            'token_type' => 'page',
            'token_expires_at' => $expiresAt,
            'scopes' => (array) config('gnext.meta.scopes'),
            'is_active' => true,
            'last_checked_at' => now(),
            'last_error' => null,
        ]);

        if (! empty($page['instagram'])) {
            $instagram = $page['instagram'];

            $connected[] = $this->upsert([
                'workspace_id' => $this->credential->workspace_id,
                'platform' => Platform::Instagram,
                'page_id' => $page['page_id'],
                'ig_user_id' => $instagram['ig_user_id'],
            ], [
                'name' => $instagram['name'] ?? $page['name'],
                'username' => $instagram['username'] ?? null,
                'avatar_url' => $instagram['avatar_url'] ?? null,
                // Instagram publishing uses the Page token, not a separate one.
                'access_token' => $page['access_token'],
                'token_type' => 'page',
                'token_expires_at' => $expiresAt,
                'scopes' => (array) config('gnext.meta.scopes'),
                'is_active' => true,
                'last_checked_at' => now(),
                'last_error' => null,
            ]);
        }

        return $connected;
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(array $identity, array $attributes): SocialAccount
    {
        return SocialAccount::withoutGlobalScopes()->updateOrCreate($identity, $attributes);
    }

    /**
     * app_id|app_secret is a valid app access token for debug_token, and avoids
     * a second round trip to fetch one.
     */
    private function appToken(): string
    {
        return $this->credential->meta_app_id.'|'.$this->credential->meta_app_secret;
    }

    private function client(): MetaClient
    {
        return new MetaClient($this->credential);
    }
}
