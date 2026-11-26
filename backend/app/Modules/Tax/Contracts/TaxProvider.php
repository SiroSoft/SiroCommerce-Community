<?php

declare(strict_types=1);

namespace App\Modules\Tax\Contracts;

interface TaxProvider
{
    /**
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $lines
     * @return array{total_minor: int, lines: array<int, array<string, mixed>>, rules: array<int, array<string, mixed>>, currency?: string, tax_inclusive?: bool, evidence_hash?: string}
     */
    public function calculate(array $context, array $lines): array;
}
