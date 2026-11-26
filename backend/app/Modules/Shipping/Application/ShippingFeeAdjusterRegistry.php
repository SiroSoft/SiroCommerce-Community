<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Application;

use App\Modules\Shipping\Contracts\ShippingFeeAdjuster;
use Siro\Core\Logger;

/**
 * Small indirection so extensions can contribute shipping fees without core
 * depending on any extension class. Empty by default.
 */
final class ShippingFeeAdjusterRegistry
{
    /** @var list<string> */
    public const METHODS = ['cod', 'standard', 'express'];

    /** @var array<string, ShippingFeeAdjuster> */
    private static array $adjusters = [];

    public static function register(string $id, ShippingFeeAdjuster $adjuster): void
    {
        self::$adjusters[$id] = $adjuster;
    }

    public static function unregister(string $id): void
    {
        unset(self::$adjusters[$id]);
    }

    public static function reset(): void
    {
        self::$adjusters = [];
    }

    public static function has(): bool
    {
        return self::$adjusters !== [];
    }

    public static function normalizeMethod(mixed $method): ?string
    {
        if (!is_string($method)) {
            return null;
        }
        $normalized = strtolower(trim($method));
        return in_array($normalized, self::METHODS, true) ? $normalized : null;
    }

    /** @param array<string, mixed> $scope */
    public static function fee(array $scope, int $subtotalMinor, string $method): int
    {
        if (self::$adjusters === []) {
            return 0;
        }
        $method = strtolower(trim($method));
        if ($method === '') {
            return 0;
        }
        $fee = 0;
        foreach (self::$adjusters as $id => $adjuster) {
            try {
                $value = $adjuster->fee($scope, $subtotalMinor, $method);
            } catch (\Throwable $exception) {
                try {
                    Logger::warning('shipping.fee_adjuster.failed: ' . $id . ': ' . $exception->getMessage());
                } catch (\Throwable) {
                }
                continue;
            }
            if ($value > 0) {
                $fee += $value;
            }
        }
        return $fee;
    }
}
