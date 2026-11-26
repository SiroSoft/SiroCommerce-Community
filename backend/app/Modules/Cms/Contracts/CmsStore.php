<?php

declare(strict_types=1);

namespace App\Modules\Cms\Contracts;

interface CmsStore
{
    /** @return array<int, array<string, mixed>> */
    public function list(?\App\Support\CommerceScope $scope = null): array;
    /** @return array<string, mixed>|null */
    public function published(string $slug, string $locale, ?\App\Support\CommerceScope $scope = null): ?array;
    /** @return array<string, mixed>|null */
    public function preview(string $id, ?\App\Support\CommerceScope $scope = null): ?array;
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array;
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $data, ?\App\Support\CommerceScope $scope = null): ?array;
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function addBlock(string $pageId, array $data, ?\App\Support\CommerceScope $scope = null): ?array;
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function updateBlock(string $id, array $data, ?\App\Support\CommerceScope $scope = null): ?array;
    /** @return array<int, array<string, mixed>> */
    public function revisions(string $entityType, string $entityId, \App\Support\CommerceScope $scope): array;
    /** @return array<string, mixed> */
    public function diff(string $entityType, string $entityId, string $fromRevision, string $toRevision, \App\Support\CommerceScope $scope): array;
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function autosave(string $id, array $data, \App\Support\CommerceScope $scope, string $actorId, string $idempotencyKey, ?int $expectedVersion = null): ?array;
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function merge(string $id, string $revisionId, array $data, \App\Support\CommerceScope $scope, string $actorId, string $idempotencyKey, ?int $expectedVersion = null): ?array;
    /** @return array<string, mixed>|null */
    public function publish(string $id, \App\Support\CommerceScope $scope, string $actorId, string $idempotencyKey): ?array;
    /** @return array<string, mixed>|null */
    public function restore(string $id, string $revisionId, \App\Support\CommerceScope $scope, string $actorId, string $idempotencyKey): ?array;
    /** @return array<string, mixed>|null */
    public function menu(string $key, string $locale): ?array;
    public function publishScheduled(string $now): int;
}
