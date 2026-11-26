<?php

declare(strict_types=1);

namespace App\Contracts;

interface ProviderHealthInterface
{
    /** @return array{ok: bool, message: string, details: array<string, mixed>} */
    public function health(): array;
}
