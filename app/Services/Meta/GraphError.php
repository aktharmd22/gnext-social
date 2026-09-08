<?php

declare(strict_types=1);

namespace App\Services\Meta;

/**
 * A Graph API failure, classified.
 *
 * Two jobs, both load-bearing:
 *
 * 1. Decide whether retrying could possibly help. Burning three attempts and
 *    45 minutes of backoff on an expired token or a 1:1 image submitted as a
 *    Reel is worse than failing immediately, because the post silently misses
 *    its slot either way and the operator finds out later.
 *
 * 2. Say what actually happened in a sentence a marketer can act on. Meta says
 *    "(#100) Invalid parameter". We say which image, why it was rejected, and
 *    what to do about it.
 */
final class GraphError
{
    public function __construct(
        public readonly ?int $code,
        public readonly ?int $subcode,
        public readonly string $message,
        public readonly ?string $type = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $fbTraceId = null,
    ) {}

    /**
     * Build from a Graph error envelope: {"error": {...}}.
     *
     * @param  array<string, mixed>  $body
     */
    public static function fromResponse(array $body, ?int $httpStatus = null): self
    {
        $error = $body['error'] ?? [];

        return new self(
            code: isset($error['code']) ? (int) $error['code'] : null,
            subcode: isset($error['error_subcode']) ? (int) $error['error_subcode'] : null,
            message: (string) ($error['error_user_msg'] ?? $error['message'] ?? 'Meta returned an error with no message.'),
            type: isset($error['type']) ? (string) $error['type'] : null,
            httpStatus: $httpStatus,
            fbTraceId: isset($error['fbtrace_id']) ? (string) $error['fbtrace_id'] : null,
        );
    }

    /**
     * A transport-level failure: DNS, connection refused, read timeout. Always
     * worth retrying -- we never learned whether Meta received the request.
     */
    public static function fromTransport(string $message): self
    {
        return new self(
            code: null,
            subcode: null,
            message: $message,
            type: 'transport',
        );
    }

    /**
     * Errors that mean "try again later, this may well succeed".
     *
     * Rate limits, transient Meta faults, and 5xx. Everything else is treated
     * as terminal, because an unknown error that repeats is indistinguishable
     * from a permanent one and we would rather fail loudly and early.
     */
    public function isRetryable(): bool
    {
        if ($this->type === 'transport') {
            return true;
        }

        if ($this->httpStatus !== null && $this->httpStatus >= 500) {
            return true;
        }

        return match ($this->code) {
            1,      // Unknown / transient
            2,      // Service temporarily unavailable
            4,      // Application request limit reached
            17,     // User request limit reached
            32,     // Page request limit reached
            341,    // Application limit reached
            613,    // Calls to this API have exceeded the rate limit
            80004 => true, // Instagram publishing limit for this rolling window
            default => false,
        };
    }

    /**
     * The token is dead and no retry will fix it. An admin must reconnect.
     */
    public function isTokenProblem(): bool
    {
        if ($this->code === 190) {
            return true;
        }

        return in_array($this->subcode, [458, 460, 463, 467, 492], true);
    }

    public function isPermissionProblem(): bool
    {
        return in_array($this->code, [10, 200, 803], true);
    }

    /**
     * Meta could not fetch or decode the media we pointed it at. Almost always
     * a Drive link that was never resolved to real bytes, or a format Meta
     * refuses.
     */
    public function isMediaProblem(): bool
    {
        return in_array($this->code, [
            9004,       // Media could not be fetched from the given URL
            2207003,    // Media download error
            2207020,    // Media fetch failed
            2207026,    // Unsupported video format
            2207032,    // Media creation failed
            2207052,    // Media fetch could not complete
        ], true);
    }

    public function isRateLimit(): bool
    {
        return in_array($this->code, [4, 17, 32, 341, 613, 80004], true);
    }

    /**
     * A short, stable string stored on post_targets.error_code so failures can
     * be grouped and filtered without parsing prose.
     */
    public function shortCode(): string
    {
        return match (true) {
            $this->type === 'transport' => 'transport',
            $this->isTokenProblem() => 'token_expired',
            $this->isPermissionProblem() => 'permission_denied',
            $this->isRateLimit() => 'rate_limited',
            $this->isMediaProblem() => 'media_rejected',
            $this->code !== null => 'graph_'.$this->code,
            default => 'unknown',
        };
    }

    /**
     * What we show a human, and what we email them.
     *
     * Never "An error occurred." Always what happened, and what to do next.
     */
    public function userMessage(?string $accountName = null): string
    {
        $account = $accountName !== null ? '"'.$accountName.'"' : 'this account';

        if ($this->isTokenProblem()) {
            return "The access token for {$account} is no longer valid. "
                .'Reconnect the page in Settings to resume publishing.';
        }

        if ($this->isPermissionProblem()) {
            return "The connected app is not permitted to publish to {$account}. "
                .'Check that the page is still connected and that the app has '
                .'pages_manage_posts and instagram_content_publish granted.';
        }

        if ($this->code === 80004) {
            return "Instagram will not accept more posts for {$account} right now: "
                .'the account has hit its limit of 50 published posts in 24 hours. '
                .'This will publish automatically once the window clears.';
        }

        if ($this->isRateLimit()) {
            return 'Meta is rate limiting us at the moment. This will be retried automatically.';
        }

        if ($this->isMediaProblem()) {
            return 'Meta could not read the media for this post. '
                .'This usually means the file was never fetched to a public URL, '
                .'or the format is one Instagram refuses. Re-fetch the media and try again.';
        }

        if ($this->type === 'transport') {
            return 'We could not reach Meta: '.$this->message.'. This will be retried automatically.';
        }

        // Meta's own user-facing message is often good; fall back to it rather
        // than inventing something vaguer.
        return rtrim($this->message, '.').'.';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'subcode' => $this->subcode,
            'type' => $this->type,
            'http_status' => $this->httpStatus,
            'message' => $this->message,
            'fbtrace_id' => $this->fbTraceId,
            'retryable' => $this->isRetryable(),
        ];
    }
}
