<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\TaxRule;
use App\Modules\Tax\Contracts\TaxProvider;
use App\Modules\Shared\Domain\Currency;
use App\Modules\Shared\Domain\Money;
use App\Modules\Tax\Domain\TaxEvidence;
use Siro\Core\Env;
use App\Modules\Commerce\Domain\RegionalRules;

/**
 * Applies native tax rules and records their evidence.
 *
 * The default policy is still "most specific single rule wins". When at least
 * one matching rule opts into compounding, every matching rule is applied in
 * `priority ASC, rate_basis_points DESC` order and the per-rate taxes are
 * summed (capped at the line total).
 */
final class NativeTaxProvider implements TaxProvider
{
    /** @param array<string, mixed> $context @param array<int, array<string, mixed>> $lines */
    public function calculate(array $context, array $lines): array
    {
        $currency = Currency::normalize(is_string($context['currency'] ?? null) ? $context['currency'] : 'VND');
        $nestedContext = is_array($context['context'] ?? null) ? $context['context'] : [];
        $taxInclusive = ($context['tax_inclusive'] ?? ($nestedContext['tax_inclusive'] ?? false)) === true;
        $rawContext = is_array($context['context'] ?? null) ? $context['context'] : $context;
        /** @var array<string, mixed> $pricingContext */
        $pricingContext = [];
        foreach ($rawContext as $key => $value) if (is_string($key)) $pricingContext[$key] = $value;
        /** @var array<string, mixed> $destination */
        $destination = [];
        $rawDestination = is_array($pricingContext['destination'] ?? null) ? $pricingContext['destination'] : $pricingContext;
        foreach ($rawDestination as $key => $value) if (is_string($key)) $destination[$key] = $value;
        $country = $this->destinationValue($destination, 'country');
        $region = $this->destinationValue($destination, 'region');
        $postalCode = $this->destinationValue($destination, 'postal_code');
        if ($country !== '' || $this->isProduction()) {
            try {
                $canonicalDestination = RegionalRules::address(['country' => $country, 'region' => $region, 'postal_code' => $postalCode]);
            } catch (\InvalidArgumentException $error) {
                if ($this->isProduction()) throw new \RuntimeException($error->getMessage(), 0, $error);
                throw $error;
            }
            $country = $canonicalDestination['country'];
            $region = $canonicalDestination['region'];
            $postalCode = $canonicalDestination['postal_code'];
        }
        if ($this->isProduction() && $country === '') {
            throw new \RuntimeException('Tax destination country is required in production');
        }
        /** @var array<int, TaxRule> $rules */
        $rules = TaxRule::query()->where('state', 'active')->get();
        $taxLines = [];
        $total = 0;
        $appliedRules = [];
        $compoundApplied = false;

        foreach ($lines as $line) {
            if (!is_array($line) || (!is_int($line['line_total_minor'] ?? null) && !(is_string($line['line_total_minor'] ?? null) && ctype_digit($line['line_total_minor'])))) {
                throw new \InvalidArgumentException('Tax line total is required and must be an integer');
            }
            $taxClass = is_string($line['tax_class'] ?? null) && $line['tax_class'] !== ''
                ? $line['tax_class']
                : 'standard';
            $matching = $this->matchingRules($rules, $country, $region, $postalCode, $taxClass);
            if ($matching === [] && $this->isProduction()) {
                throw new \RuntimeException('No active tax rule matches the production destination');
            }
            $lineTotalValue = $line['line_total_minor'];
            $lineTotal = (int) $lineTotalValue;
            if ($lineTotal < 0) throw new \InvalidArgumentException('Tax line total must not be negative');
            if (array_key_exists('currency', $line)) {
                if (!is_string($line['currency'])) throw new \InvalidArgumentException('Tax line currency must be a string');
                try { $lineCurrency = Currency::normalize($line['currency']); } catch (\InvalidArgumentException $error) { throw new \InvalidArgumentException('Unsupported tax line currency', 0, $error); }
                if ($lineCurrency !== $currency) throw new \InvalidArgumentException('Tax line currency mismatch');
            }

            if ($matching === []) {
                $evidence = $this->evidence(null, $country, $region, $postalCode, $taxClass, 0);
                $appliedRules[$this->evidenceCode($evidence)] = $evidence;
                $variantValue = $line['variant_id'] ?? '';
                $taxLines[] = ['variant_id' => is_scalar($variantValue) ? (string) $variantValue : '', 'tax_class' => $taxClass, 'tax_minor' => 0, 'currency' => $currency, 'evidence' => $evidence];
                continue;
            }

            if ($this->hasCompoundRule($matching)) {
                $compoundApplied = true;
                $ordered = $this->orderForCompound($matching);
                $taxMinor = 0;
                $sumRate = 0;
                $ruleEvidence = [];
                foreach ($ordered as $rule) {
                    $rate = $this->validateRate($rule);
                    $taxMinor += $taxInclusive
                        ? Money::roundedRatio($lineTotal, $rate, 10000 + $rate)
                        : Money::roundedRatio($lineTotal, $rate, 10000);
                    $sumRate += $rate;
                    $ruleEvidence[] = $this->evidence($rule, $country, $region, $postalCode, $taxClass, $rate);
                }
                $taxMinor = min($taxMinor, $lineTotal);
                $codes = [];
                foreach ($ruleEvidence as $candidate) {
                    $code = $this->evidenceCode($candidate);
                    $codes[] = $code;
                    $appliedRules[$code] = $candidate;
                }
                $evidence = [
                    'mode' => 'compound',
                    'code' => $codes[0] ?? 'compound',
                    'codes' => $codes,
                    'rules' => $ruleEvidence,
                    'destination' => ['country' => $country, 'region' => $region, 'postal_code' => $postalCode],
                    'tax_class' => $taxClass,
                    'rate_basis_points' => $sumRate,
                    'rate' => $sumRate / 100,
                ];
            } else {
                $rule = $this->bestRule($matching);
                if ($rule === null) {
                    throw new \RuntimeException('Tax rule selection failed');
                }
                $rate = $this->validateRate($rule);
                $taxMinor = $taxInclusive
                    ? Money::roundedRatio($lineTotal, $rate, 10000 + $rate)
                    : Money::roundedRatio($lineTotal, $rate, 10000);
                $evidence = $this->evidence($rule, $country, $region, $postalCode, $taxClass, $rate);
                $appliedRules[$this->evidenceCode($evidence)] = $evidence;
            }

            $total = (new Money($total, $currency))->add(new Money($taxMinor, $currency))->amountMinor;
            $variantValue = $line['variant_id'] ?? '';
            $taxLines[] = ['variant_id' => is_scalar($variantValue) ? (string) $variantValue : '', 'tax_class' => $taxClass, 'tax_minor' => $taxMinor, 'currency' => $currency, 'evidence' => $evidence];
        }

        if (!$compoundApplied) ksort($appliedRules);
        $result = ['total_minor' => $total, 'lines' => $taxLines, 'rules' => array_values($appliedRules), 'currency' => $currency, 'tax_inclusive' => $taxInclusive];
        $result['evidence_hash'] = TaxEvidence::hash($result);
        return $result;
    }

    /**
     * @param array<int, TaxRule> $rules
     * @return array<int, TaxRule>
     */
    private function matchingRules(array $rules, string $country, string $region, string $postalCode, string $taxClass): array
    {
        $now = date('Y-m-d H:i:s');
        $matching = [];
        foreach ($rules as $rule) {
            $effectiveAt = $this->stringAttribute($rule, 'effective_at');
            $expiresAt = $this->stringAttribute($rule, 'expires_at');
            if (($effectiveAt !== '' && $effectiveAt > $now) || ($expiresAt !== '' && $expiresAt <= $now)) continue;
            if ($this->stringAttribute($rule, 'tax_class') !== $taxClass) continue;
            /** @var array<string, string> $dimensions */
            $dimensions = ['destination_country' => $country, 'destination_region' => $region, 'destination_postal_code' => $postalCode];
            $isMatch = true;
            foreach ($dimensions as $field => $value) {
                $expected = $rule->getAttribute($field);
                if ($expected === null) continue;
                if (!is_scalar($expected) || (string) $expected !== $value) {
                    $isMatch = false;
                    break;
                }
            }
            if ($isMatch) $matching[] = $rule;
        }
        return $matching;
    }

    /**
     * Most specific single rule: specificity beats priority.
     *
     * @param array<int, TaxRule> $rules
     */
    private function bestRule(array $rules): ?TaxRule
    {
        $best = null;
        $bestScore = -1;
        foreach ($rules as $rule) {
            $score = 0;
            foreach (['destination_country', 'destination_region', 'destination_postal_code'] as $field) {
                if ($rule->getAttribute($field) !== null) $score += 1;
            }
            $score = ($score * 100000) + $this->integerAttribute($rule, 'priority');
            if ($score > $bestScore) { $best = $rule; $bestScore = $score; }
        }
        return $best;
    }

    /** @param array<int, TaxRule> $rules */
    private function hasCompoundRule(array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($this->integerAttribute($rule, 'compound') === 1) return true;
        }
        return false;
    }

    /**
     * @param array<int, TaxRule> $rules
     * @return array<int, TaxRule>
     */
    private function orderForCompound(array $rules): array
    {
        usort($rules, function (TaxRule $a, TaxRule $b): int {
            $priority = $this->integerAttribute($a, 'priority') <=> $this->integerAttribute($b, 'priority');
            if ($priority !== 0) return $priority;
            $rate = $this->integerAttribute($b, 'rate_basis_points') <=> $this->integerAttribute($a, 'rate_basis_points');
            if ($rate !== 0) return $rate;
            return strcmp($this->stringAttribute($a, 'code'), $this->stringAttribute($b, 'code'));
        });
        return $rules;
    }

    private function validateRate(TaxRule $rule): int
    {
        $rate = $this->integerAttribute($rule, 'rate_basis_points');
        if ($rate < 0 || $rate > 10000) {
            throw new \RuntimeException('Tax rule rate must be between 0 and 10000 basis points');
        }
        return $rate;
    }

    /** @return array<string, mixed> */
    private function evidence(?TaxRule $rule, string $country, string $region, string $postalCode, string $taxClass, int $rate): array
    {
        if ($rule === null) {
            return ['code' => 'explicit-zero-tax-no-match', 'policy' => 'explicit_zero_tax', 'reason' => 'no_active_matching_rule', 'destination' => ['country' => $country, 'region' => $region, 'postal_code' => $postalCode], 'tax_class' => $taxClass, 'rate_basis_points' => 0, 'rate' => 0];
        }
        return ['code' => $this->stringAttribute($rule, 'code'), 'rule_id' => $this->stringAttribute($rule, 'id'), 'source' => $this->stringAttribute($rule, 'source'), 'evidence' => $this->stringAttribute($rule, 'evidence'), 'destination' => ['country' => $country, 'region' => $region, 'postal_code' => $postalCode], 'tax_class' => $taxClass, 'rate_basis_points' => $rate, 'rate' => $rate / 100];
    }

    /** @param array<string, mixed> $evidence */
    private function evidenceCode(array $evidence): string
    {
        return is_string($evidence['code'] ?? null) ? $evidence['code'] : 'unknown';
    }

    /** @param array<string, mixed> $destination */
    private function destinationValue(array $destination, string $key): string
    {
        $aliases = ['region' => ['state', 'province'], 'postal_code' => ['postal', 'zip']];
        $value = $destination[$key] ?? $destination['destination_' . $key] ?? '';
        if ($value === '' && isset($aliases[$key])) {
            foreach ($aliases[$key] as $alias) {
                if (isset($destination[$alias])) { $value = $destination[$alias]; break; }
            }
        }
        return is_scalar($value) ? strtoupper(trim((string) $value)) : '';
    }

    private function stringAttribute(TaxRule $rule, string $key): string
    {
        $value = $rule->getAttribute($key);
        return is_scalar($value) ? (string) $value : '';
    }

    private function integerAttribute(TaxRule $rule, string $key): int
    {
        $value = $rule->getAttribute($key);
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    private function isProduction(): bool
    {
        $environment = getenv('APP_ENV');
        return strtolower(trim(is_string($environment) && $environment !== '' ? $environment : (string) Env::get('APP_ENV', 'local'))) === 'production';
    }
}
