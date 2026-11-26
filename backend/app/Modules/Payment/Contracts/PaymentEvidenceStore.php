<?php

declare(strict_types=1);

namespace App\Modules\Payment\Contracts;

interface PaymentEvidenceStore
{
    /** @param array<string, mixed> $evidence */
    public function recordEvidence(string $id, array $evidence): void;
}
