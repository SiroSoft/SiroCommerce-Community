<?php
declare(strict_types=1);
namespace App\Modules\Dev\Contracts;
interface DevOperations
{
    /** @return array<string, mixed> */ public function trace(string $id): array;
    /** @return array<int, array<string, mixed>> */ public function diagnose(string $scope): array;
    /** @return array<string, mixed> */ public function releaseExpired(bool $dry, string $actor): array;
    /** @return array<string, mixed> */ public function rescue(string $id, string $actor, bool $dry): array;
}
