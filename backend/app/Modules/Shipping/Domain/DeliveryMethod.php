<?php
declare(strict_types=1);
namespace App\Modules\Shipping\Domain;

final readonly class DeliveryMethod implements \App\Modules\Shipping\Contracts\DeliveryMethodContract
{
    /** @param array<string, mixed> $rule */
    public function __construct(
        public string $code,
        public string $label,
        public int $baseCostMinor,
        public string $currency,
        public array $rule = [],
    ) {
        if ($code === '' || $baseCostMinor < 0 || !preg_match('/^[A-Z0-9_.-]+$/', $code)) throw new \InvalidArgumentException('Invalid delivery method.');
    }

    /** @param array<string, mixed> $context */
    public function available(array $context): bool
    {
        $rawCountry = $context['country'] ?? '';
        $country = strtoupper(is_scalar($rawCountry) ? (string) $rawCountry : '');
        $countries = $this->rule['countries'] ?? null;
        if (is_array($countries) && $countries !== []) {
            $normalized = [];
            foreach ($countries as $candidate) if (is_scalar($candidate)) $normalized[] = strtoupper((string) $candidate);
            if (!in_array($country, $normalized, true)) return false;
        }
        $minimum = $this->rule['minimum_subtotal_minor'] ?? null;
        $subtotal = $context['subtotal_minor'] ?? 0;
        return !is_numeric($minimum) || (is_int($subtotal) || is_float($subtotal) || is_string($subtotal)) && (int) $subtotal >= (int) $minimum;
    }

    /** @param array<string, mixed> $context */
    public function costMinor(array $context): int
    {
        if (!$this->available($context)) throw new \DomainException('Delivery method is unavailable.');
        $freeOver = $this->rule['free_over_minor'] ?? null;
        $subtotal = $context['subtotal_minor'] ?? 0;
        return is_numeric($freeOver) && (is_int($subtotal) || is_float($subtotal) || is_string($subtotal)) && (int) $subtotal >= (int) $freeOver ? 0 : $this->baseCostMinor;
    }
}
