<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain;

use InvalidArgumentException;

/** Immutable integer-minor-unit money value object. */
final readonly class Money
{
    public const MAX_MINOR = 2147483647;

    public function __construct(
        public int $amountMinor,
        public string $currency,
    ) {
        if ($this->currency !== Currency::normalize($this->currency)) throw new InvalidArgumentException('Currency must be an ISO-4217 uppercase code');
        Currency::assertAmount($this->currency, $this->amountMinor);
        if ($this->amountMinor > self::MAX_MINOR) throw new InvalidArgumentException('Minor amount exceeds the signed INT storage limit');
    }

    public function add(self $other): self
    {
        $this->sameCurrency($other);
        if ($other->amountMinor > self::MAX_MINOR - $this->amountMinor) throw new InvalidArgumentException('Minor amount exceeds the signed INT storage limit');
        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->sameCurrency($other);
        if ($other->amountMinor > $this->amountMinor) throw new InvalidArgumentException('Minor amount cannot be negative');
        return new self($this->amountMinor - $other->amountMinor, $this->currency);
    }

    public function multiply(int $factor): self
    {
        if ($factor < 0 || ($factor !== 0 && $this->amountMinor > intdiv(self::MAX_MINOR, $factor))) throw new InvalidArgumentException('Minor amount exceeds the signed INT storage limit');
        return new self($this->amountMinor * $factor, $this->currency);
    }

    public static function ratio(int $amount, int $numerator, int $denominator): int
    {
        if ($amount < 0 || $amount > self::MAX_MINOR || $numerator < 0 || $denominator <= 0) throw new InvalidArgumentException('Invalid money ratio');
        $whole = intdiv($amount, $denominator) * $numerator;
        $remainder = intdiv(($amount % $denominator) * $numerator, $denominator);
        if ($whole > self::MAX_MINOR - $remainder) throw new InvalidArgumentException('Minor amount exceeds the signed INT storage limit');
        return $whole + $remainder;
    }

    public static function roundedRatio(int $amount, int $numerator, int $denominator): int
    {
        $result = self::ratio($amount, $numerator, $denominator);
        $remainder = (($amount % $denominator) * $numerator) % $denominator;
        if ($remainder >= intdiv($denominator + 1, 2)) {
            if ($result === self::MAX_MINOR) throw new InvalidArgumentException('Minor amount exceeds the signed INT storage limit');
            return $result + 1;
        }
        return $result;
    }

    /** @return array{amount_minor: int, currency: string} */
    public function toArray(): array
    {
        return ['amount_minor' => $this->amountMinor, 'currency' => $this->currency];
    }

    private function sameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Currency mismatch');
        }
    }
}
