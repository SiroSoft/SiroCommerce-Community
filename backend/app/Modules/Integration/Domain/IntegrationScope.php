<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain;

final class IntegrationScope
{
    /** @param array<int,string> $granted */
    public static function allows(array $granted, string $required): bool
    {
        if ($required === '' || in_array('integration:*', $granted, true)) return $required !== '';
        foreach ($granted as $scope) {
            if ($scope === $required || ($scope !== '' && str_ends_with($scope, ':*') && str_starts_with($required, substr($scope, 0, -1)))) return true;
        }
        return false;
    }

    /**
     * @param array<int,string> $granted
     * @param array<int,string> $required
     */
    public static function requireAll(array $granted, array $required): void
    {
        foreach ($required as $scope) if (!self::allows($granted, $scope)) throw new \RuntimeException('Integration key scope is insufficient.');
    }
}
