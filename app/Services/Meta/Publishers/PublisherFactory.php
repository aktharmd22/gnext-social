<?php

declare(strict_types=1);

namespace App\Services\Meta\Publishers;

use App\Enums\Platform;
use App\Models\AppCredential;
use App\Models\SocialAccount;
use App\Services\Meta\MetaClient;
use RuntimeException;

/**
 * Builds the right publisher for a destination, wired to the workspace's own
 * Meta app credentials.
 *
 * Credentials are per workspace and admin-editable at runtime, so a publisher
 * cannot be a singleton -- it is constructed per publish attempt.
 */
class PublisherFactory
{
    /**
     * A pre-built client may be passed in so the caller can attach an exchange
     * recorder before any request is made -- that is how every attempt reaches
     * publish_logs, including the ones that fail.
     */
    public function for(SocialAccount $account, ?MetaClient $client = null): Publisher
    {
        $client ??= $this->clientFor($account);

        return match ($account->platform) {
            Platform::Facebook => new FacebookPublisher($client),
            Platform::Instagram => new InstagramPublisher($client),
        };
    }

    public function clientFor(SocialAccount $account): MetaClient
    {
        $credential = AppCredential::withoutGlobalScopes()
            ->where('workspace_id', $account->workspace_id)
            ->first();

        if ($credential === null || ! $credential->isUsable()) {
            throw new RuntimeException(
                'No Meta app is configured for this workspace. '
                .'Add an App ID and secret in Settings before publishing.'
            );
        }

        return new MetaClient($credential);
    }
}
