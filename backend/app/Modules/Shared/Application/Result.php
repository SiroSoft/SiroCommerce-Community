<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application;

final readonly class Result
{
    private function __construct(
        public bool $ok,
        public mixed $value,
        public ?string $code,
        public ?string $message,
    ) {
    }

    /** @param mixed $value */
    public static function success(mixed $value): self
    {
        return new self(true, $value, null, null);
    }

    public static function failure(string $code, string $message): self
    {
        return new self(false, null, $code, $message);
    }

    public function unwrap(): mixed
    {
        if (!$this->ok) {
            throw new \RuntimeException($this->code . ': ' . $this->message);
        }
        return $this->value;
    }
}
