<?php
declare(strict_types=1);
namespace App\Modules\Dev\Application;
use App\Modules\Dev\Contracts\DevOperations;
final class DevService
{
    public function __construct(private readonly DevOperations $operations) {}
    /** @return array<string, mixed> */ public function trace(string $id): array { return $this->operations->trace($id); }
    /** @return array<int, array<string, mixed>> */ public function diagnose(string $scope): array { return $this->operations->diagnose($scope); }
    /** @return array<string, mixed> */ public function releaseExpired(bool $dry, string $actor): array { return $this->operations->releaseExpired($dry, $actor); }
    /** @return array<string, mixed> */ public function rescue(string $id, string $actor, bool $dry): array { return $this->operations->rescue($id, $actor, $dry); }
}
