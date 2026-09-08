<?php

declare(strict_types=1);

namespace App\Services\Meta;

use RuntimeException;

class GraphException extends RuntimeException
{
    public function __construct(public readonly GraphError $error)
    {
        parent::__construct($error->message, $error->code ?? 0);
    }

    public function isRetryable(): bool
    {
        return $this->error->isRetryable();
    }

    public function userMessage(?string $accountName = null): string
    {
        return $this->error->userMessage($accountName);
    }
}
