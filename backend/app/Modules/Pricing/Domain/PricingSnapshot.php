<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use App\Modules\Shared\Domain\Money;

/** Immutable calculated commercial result stored on cart/order. */
final readonly class PricingSnapshot
{
    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<int, array<string, mixed>> $rules
     * @param array<int, array<string, mixed>> $taxes
     */
    public function __construct(
        public string $id,
        public string $engineVersion,
        public array $lines,
        public Money $subtotal,
        public Money $discount,
        public Money $shipping,
        public Money $tax,
        public Money $grandTotal,
        public array $rules = [],
        public array $taxes = [],
        public ?string $expiresAt = null,
        public bool $taxInclusive = false,
    ) {
        if ($this->lines === []) {
            throw new \InvalidArgumentException('Pricing snapshot requires lines');
        }
        $expectedMoney = $this->subtotal->subtract($this->discount)->add($this->shipping);
        if (!$this->taxInclusive) $expectedMoney = $expectedMoney->add($this->tax);
        $expected = $expectedMoney->amountMinor;
        if ($expected !== $this->grandTotal->amountMinor) {
            throw new \InvalidArgumentException('Pricing snapshot total mismatch');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'engine_version' => $this->engineVersion,
            'lines' => $this->lines,
            'subtotal' => $this->subtotal->toArray(),
            'discount' => $this->discount->toArray(),
            'shipping' => $this->shipping->toArray(),
            'tax' => $this->tax->toArray(),
            'grand_total' => $this->grandTotal->toArray(),
            'rules' => $this->rules,
            'taxes' => $this->taxes,
            'expires_at' => $this->expiresAt,
            'tax_inclusive' => $this->taxInclusive,
        ];
    }
}
