<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Support\Arr;

/** Typed boundary for product-type and composite component snapshots. */
final class ProductTypeDefinition
{
    /** @var list<string> */
    private const TYPES = ['simple', 'configurable', 'grouped', 'bundle', 'virtual', 'downloadable', 'voucher', 'booking'];

    public function __construct(
        public readonly string $type,
        public readonly string $bundlePricingMode,
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported product type');
        }
        if (!in_array($bundlePricingMode, ['component_sum', 'fixed'], true)) {
            throw new \InvalidArgumentException('Unsupported bundle pricing mode');
        }
    }

    /** @param array<string, mixed> $line */
    public static function fromLine(array $line): self
    {
        return new self(Arr::str($line, 'product_type', 'simple'), Arr::str($line, 'bundle_pricing_mode', 'component_sum'));
    }

    /** @param array<int, array<string, mixed>> $components */
    public static function forBundle(string $mode, array $components = []): self
    {
        $definition = new self('bundle', $mode);
        foreach ($components as $component) {
            $componentMode = Arr::str($component, 'bundle_pricing_mode');
            if ($componentMode !== '' && $componentMode !== $mode) {
                throw new \InvalidArgumentException('Bundle components use inconsistent pricing modes');
            }
        }
        return $definition;
    }

    /**
     * Canonicalize persisted and API component snapshots at the module boundary.
     * A conflicting legacy identifier is stale data, not a substitute.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeComponents(mixed $raw, bool $required = true): array
    {
        if (!is_array($raw)) {
            throw new \RuntimeException('Invalid bundle components');
        }
        $components = [];
        foreach ($raw as $value) {
            if (!is_array($value)) {
                throw new \RuntimeException('Invalid bundle component snapshot');
            }
            $component = Arr::row($value);
            $canonical = Arr::nullableStr($component, 'component_variant_id');
            $legacy = Arr::nullableStr($component, 'variant_id');
            if ($canonical === null || $canonical === '') {
                $canonical = $legacy;
            } elseif ($legacy !== null && $legacy !== '' && $legacy !== $canonical) {
                throw new \RuntimeException('Stale bundle component identifiers');
            }
            if ($canonical === null || $canonical === '') {
                throw new \InvalidArgumentException('Missing required string: component_variant_id');
            }
            $component['component_variant_id'] = $canonical;
            unset($component['variant_id']);
            $qty = Arr::requiredInt($component, 'qty');
            if ($qty <= 0) {
                throw new \InvalidArgumentException('Bundle component quantity must be positive');
            }
            $components[] = $component;
        }
        if ($required && $components === []) {
            throw new \RuntimeException('Bundle has no components');
        }
        return $components;
    }
}
