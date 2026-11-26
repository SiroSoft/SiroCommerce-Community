<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Money value object — integer minor units only (spec 02§3.2). No float.
 */
final class Money
{
    /** Monetary columns use MySQL signed INT in the current schema. */
    public const MAX_MINOR = 2147483647;

    public function __construct(
        public readonly int $minor,
        public readonly string $currency = 'VND'
    ) {
        if ($minor < 0 || $minor > self::MAX_MINOR) {
            throw new \InvalidArgumentException('Money minor must be between 0 and ' . self::MAX_MINOR);
        }
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);
        if ($other->minor > self::MAX_MINOR - $this->minor) {
            throw new \InvalidArgumentException('Money minor exceeds the storage limit');
        }
        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->minor - $other->minor, $this->currency);
    }

    /**
     * Per-line rounding policy must be explicit at call site; this helper sums lines.
     *
     * @param array<int, int> $lineMinors
     */
    public static function sumLines(array $lineMinors, string $currency = 'VND'): self
    {
        $total = 0;
        foreach ($lineMinors as $m) {
            if (!is_int($m) || $m < 0 || $m > self::MAX_MINOR || $m > self::MAX_MINOR - $total) {
                throw new \InvalidArgumentException('Money minor exceeds the storage limit');
            }
            $total += $m;
        }
        return new self($total, $currency);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new \InvalidArgumentException('Currency mismatch');
        }
    }
}
