<?php

declare(strict_types=1);

namespace App\Services\Meta\Publishers;

final class PublishResult
{
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $permalink = null,
        public readonly ?string $containerId = null,
    ) {}
}
