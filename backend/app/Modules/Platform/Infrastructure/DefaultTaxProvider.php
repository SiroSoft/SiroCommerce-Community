<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Modules\Tax\Contracts\TaxProvider;
use App\Modules\Shared\Domain\Currency;
use App\Modules\Tax\Domain\TaxEvidence;
use Siro\Core\Env;

final class DefaultTaxProvider implements TaxProvider
{
    /** @param array<string, mixed> $context @param array<int, array<string, mixed>> $lines */
    public function calculate(array $context, array $lines): array
    {
        $configuredEnvironment = getenv('APP_ENV');
        $environment = strtolower(trim(is_string($configuredEnvironment) && $configuredEnvironment !== '' ? $configuredEnvironment : (string) Env::get('APP_ENV', 'local')));
        if ($environment === 'production') throw new \RuntimeException('Default zero-tax provider is forbidden in production');
        $currency = Currency::normalize(is_string($context['currency'] ?? null) ? $context['currency'] : 'VND');
        $taxLines = [];
        foreach ($lines as $line) {
            $taxLines[] = ['variant_id' => is_scalar($line['variant_id'] ?? null) ? (string) $line['variant_id'] : '', 'tax_class' => is_string($line['tax_class'] ?? null) ? $line['tax_class'] : 'standard', 'tax_minor' => 0, 'evidence' => ['code' => 'zero-rated-default', 'policy' => 'explicit_zero_tax', 'rate_basis_points' => 0]];
        }
        $result = ['total_minor' => 0, 'lines' => $taxLines, 'rules' => [['code' => 'zero-rated-default', 'policy' => 'explicit_zero_tax', 'rate' => 0]], 'currency' => $currency, 'tax_inclusive' => false];
        $result['evidence_hash'] = TaxEvidence::hash($result);
        return $result;
    }
}
