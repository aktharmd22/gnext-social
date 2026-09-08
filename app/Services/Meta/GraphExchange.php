<?php

declare(strict_types=1);

namespace App\Services\Meta;

/**
 * One request/response pair, already redacted, ready to be written to
 * publish_logs.
 *
 * The client produces these; the publisher decides what to do with them. That
 * split is why MetaClient has no idea post_targets exist.
 */
final class GraphExchange
{
    /**
     * @param  array<string, mixed>  $requestPayload  Redacted. Never contains a token.
     * @param  array<string, mixed>  $responseBody
     */
    public function __construct(
        public readonly string $method,
        public readonly string $endpoint,
        public readonly array $requestPayload,
        public readonly array $responseBody,
        public readonly ?int $httpCode,
        public readonly int $durationMs,
    ) {}

    public function succeeded(): bool
    {
        return $this->httpCode !== null && $this->httpCode < 400;
    }
}
