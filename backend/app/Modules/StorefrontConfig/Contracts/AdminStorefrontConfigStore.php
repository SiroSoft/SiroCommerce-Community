<?php
declare(strict_types=1);
namespace App\Modules\StorefrontConfig\Contracts;
use App\Support\CommerceScope;
interface AdminStorefrontConfigStore extends StorefrontConfigStore
{
    /** @return array{ id: mixed, status: mixed, version: int, config: array<string,mixed> }|null */
    public function draft(CommerceScope $scope): ?array;
    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public function saveDraft(CommerceScope $scope, array $config, int $expectedVersion, string $actor, string $key): array;
    /** @return array<string,mixed> */
    public function publish(CommerceScope $scope, int $expectedVersion, string $actor, string $key): array;
    /** @return array<string,mixed> */
    public function restore(CommerceScope $scope, string $revision, int $expectedVersion, string $actor, string $key): array;
    /** @return list<array<string,mixed>> */
    public function revisions(CommerceScope $scope): array;
    /** @return array<string,mixed> */
    public function diff(CommerceScope $scope, string $from, string $to): array;
    /** @return list<array<string,mixed>> */
    public function themes(): array;
    /** @return list<array<string,mixed>> */
    public function presets(): array;
}
