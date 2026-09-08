<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * One thing that is wrong, or might be, with a piece of media or a caption.
 *
 * `fix` names a remedy the UI can offer as a button rather than making the
 * operator go away and re-export the asset. The brief is explicit that an
 * aspect-ratio failure should offer crop or pad, not just refuse.
 */
final class ValidationVerdict
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public function __construct(
        public readonly string $level,
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $fix = null,
    ) {}

    public static function error(string $code, string $message, ?string $fix = null): self
    {
        return new self(self::ERROR, $code, $message, $fix);
    }

    public static function warning(string $code, string $message, ?string $fix = null): self
    {
        return new self(self::WARNING, $code, $message, $fix);
    }

    public function isError(): bool
    {
        return $this->level === self::ERROR;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'code' => $this->code,
            'message' => $this->message,
            'fix' => $this->fix,
        ];
    }
}
