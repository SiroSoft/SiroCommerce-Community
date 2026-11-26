<?php

declare(strict_types=1);

namespace App\Modules\Cms\Application;

use App\Modules\Cms\Contracts\CmsStore;
use App\Support\Arr;
use App\Role;
use App\Modules\Cms\Domain\CmsAccessDenied;
use App\Support\CommerceScope;
use App\Modules\Cms\Domain\CmsConflict;

final class CmsService
{
    public function __construct(private readonly CmsStore $store) {}
    /**
     * @param array<string, mixed>|null $actor
     * @return array<int, array<string, mixed>>
     */
    public function list(?array $actor = null, ?CommerceScope $scope = null): array
    {
        $this->authorize($actor, 'cms:read');
        return $this->store->list($scope);
    }

    /** @return array<string, mixed>|null */
    public function published(string $slug, string $locale = 'en', ?CommerceScope $scope = null): ?array
    {
        return $this->store->published($slug, $locale, $scope);
    }

    /**
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>|null
     */
    public function preview(string $id, ?array $actor = null, ?CommerceScope $scope = null): ?array
    {
        $this->authorize($actor, 'cms:read');
        return $this->store->preview($id, $scope);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function create(array $data, ?array $actor = null): array
    {
        $this->authorize($actor, 'cms:write');
        $data = $this->sanitize($data);
        $data['status'] = $data['status'] ?? 'draft';
        /** @var array<string, mixed> $result */
        $result = $this->store->create($data);
        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $data, ?array $actor = null, ?CommerceScope $scope = null): ?array
    {
        $this->authorize($actor, 'cms:write');
        $data = $this->sanitize($data);
        /** @var array<string, mixed>|null $result */
        $result = $this->store->update($id, $data, $scope);
        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>|null
     */
    public function addBlock(string $pageId, array $data, ?array $actor = null, ?CommerceScope $scope = null): ?array
    {
        $this->authorize($actor, 'cms:write');
        $data = $this->sanitizeBlock($data);
        /** @var array<string, mixed>|null $result */
        $result = $this->store->addBlock($pageId, $data, $scope);
        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>|null
     */
    public function updateBlock(string $id, array $data, ?array $actor = null, ?CommerceScope $scope = null): ?array
    {
        $this->authorize($actor, 'cms:write');
        $data = $this->sanitizeBlock($data);
        /** @var array<string, mixed>|null $result */
        $result = $this->store->updateBlock($id, $data, $scope);
        return $result;
    }

    /**
     * @param array<string, mixed>|null $actor
     * @return array<int, array<string, mixed>>
     */
    public function revisions(string $entityType, string $entityId, CommerceScope $scope, ?array $actor = null): array
    {
        $this->authorize($actor, 'cms:read');
        return $this->store->revisions($entityType, $entityId, $scope);
    }

    /**
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function diff(string $entityType, string $entityId, string $from, string $to, CommerceScope $scope, ?array $actor = null): array
    {
        $this->authorize($actor, 'cms:read');
        return $this->store->diff($entityType, $entityId, $from, $to, $scope);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>|null
     */
    public function autosave(string $id, array $data, CommerceScope $scope, string $idempotencyKey, ?int $expectedVersion = null, ?array $actor = null): ?array
    {
        $this->authorize($actor, 'cms:write');
        return $this->store->autosave($id, $this->sanitize($data), $scope, $this->actorId($actor), $this->mutationKey($idempotencyKey), $expectedVersion);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>|null
     */
    public function merge(string $id, string $revision, array $data, CommerceScope $scope, string $idempotencyKey, ?int $expectedVersion = null, ?array $actor = null): ?array
    {
        $this->authorize($actor, 'cms:write');
        return $this->store->merge($id, $revision, $this->sanitize($data), $scope, $this->actorId($actor), $this->mutationKey($idempotencyKey), $expectedVersion);
    }

    /**
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>|null
     */
    public function publish(string $id, CommerceScope $scope, string $idempotencyKey, ?array $actor = null): ?array
    {
        $this->authorize($actor, 'cms:write');
        $key = $this->mutationKey($idempotencyKey);
        return $this->store->publish($id, $scope, $this->actorId($actor), $key);
    }

    /**
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>|null
     */
    public function restore(string $id, string $revisionId, CommerceScope $scope, string $idempotencyKey, ?array $actor = null): ?array
    {
        $this->authorize($actor, 'cms:write');
        $key = $this->mutationKey($idempotencyKey);
        return $this->store->restore($id, $revisionId, $scope, $this->actorId($actor), $key);
    }

    /** @return array<string, mixed>|null */
    public function menu(string $key, string $locale = 'en'): ?array { return $this->store->menu($key, $locale); }

    /** @param array<string, mixed>|null $actor */
    private function authorize(?array $actor, string $capability): void
    {
        // Console jobs and trusted application services may omit an HTTP actor.
        if ($actor === null) return;
        $role = is_string($actor['role'] ?? null) ? $actor['role'] : '';
        if ($role === '' || !Role::can($role, 'cms')) {
            throw new CmsAccessDenied('CMS capability required: ' . $capability);
        }
    }

    /** @param array<string, mixed>|null $actor */
    private function actorId(?array $actor): string
    {
        $id = is_scalar($actor['id'] ?? null) ? (string) $actor['id'] : '';
        if ($id === '') throw new CmsAccessDenied('Authenticated CMS actor required.');
        return $id;
    }

    private function mutationKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > 128) throw new \InvalidArgumentException('Idempotency-Key is required');
        return $key;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        $allowed = ['slug', 'title', 'body', 'locale', 'store_id', 'channel_id', 'currency', 'status', 'published_at', 'scheduled_at', 'canonical', 'seo_title', 'seo_description', 'seo_robots', 'seo_schema_json', 'media_json'];
        /** @var array<string, mixed> $filtered */
        $filtered = [];
        foreach ($allowed as $key) if (array_key_exists($key, $data)) $filtered[$key] = $data[$key];
        $data = $filtered;
        foreach (['slug', 'locale', 'canonical', 'seo_title', 'seo_description', 'seo_robots'] as $key) if (isset($data[$key]) && !is_string($data[$key])) throw new \InvalidArgumentException('Invalid CMS field: ' . $key);
        if (isset($data['status']) && !in_array($data['status'], ['draft', 'scheduled', 'published', 'archived'], true)) throw new \InvalidArgumentException('Invalid page status');
        if (isset($data['slug']) && is_string($data['slug']) && !preg_match('/^[a-z0-9][a-z0-9-]{0,159}$/', $data['slug'])) throw new \InvalidArgumentException('Slug must be a lowercase path-safe token.');
        if (isset($data['body']) && (!is_string($data['body']) || strlen($data['body']) > 200000 || preg_match('/<\/?(script|iframe|object|embed)\b|\bon[a-z]+\s*=|javascript:/i', $data['body']) === 1)) throw new \InvalidArgumentException('Unsafe or oversized page body.');
        if (isset($data['media_json'])) $data['media_json'] = $this->mediaJson($data['media_json']);
        if (($data['status'] ?? null) === 'scheduled' && (!is_string($data['scheduled_at'] ?? null) || trim($data['scheduled_at']) === '')) throw new \InvalidArgumentException('scheduled_at is required for scheduled pages.');
        if (isset($data['seo_schema_json'])) $data['seo_schema_json'] = $this->schemaJson($data['seo_schema_json']);
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function sanitizeBlock(array $data): array
    {
        /** @var array<string, mixed> $filtered */
        $filtered = [];
        foreach (['type', 'content', 'sort', 'status', 'media_json'] as $key) if (array_key_exists($key, $data)) $filtered[$key] = $data[$key];
        $data = $filtered;
        if (isset($data['type']) && (!is_string($data['type']) || !in_array($data['type'], ['text', 'image', 'hero', 'rich_text', 'product_list', 'markdown', 'internal', 'cta', 'faq'], true))) throw new \InvalidArgumentException('Unsupported block type.');
        if (isset($data['content']) && (!is_string($data['content']) || strlen($data['content']) > 100000 || preg_match('/<\/?(script|iframe|object|embed)\b|\bon[a-z]+\s*=|javascript:|(?:^|["\'])\.\.\//i', $data['content']) === 1)) throw new \InvalidArgumentException('Unsafe or oversized block content.');
        if (isset($data['media_json'])) $data['media_json'] = $this->mediaJson($data['media_json']);
        return $data;
    }

    private function mediaJson(mixed $value): string
    {
        if (is_string($value)) $decoded = json_decode($value, true);
        else $decoded = $value;
        if (!is_array($decoded) || count($decoded) > 20) throw new \InvalidArgumentException('Media references must be a bounded list.');
        foreach ($decoded as $ref) if (!is_array($ref) || !is_string($ref['id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $ref['id']) || !is_string($ref['url'] ?? null) || !preg_match('~^https://~i', $ref['url']) || !is_string($ref['mime'] ?? null) || !in_array(strtolower($ref['mime']), ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'video/mp4', 'video/webm'], true) || !is_int($ref['size'] ?? null) || $ref['size'] < 1 || $ref['size'] > 52428800 || (isset($ref['width']) && (!is_int($ref['width']) || $ref['width'] < 1)) || (isset($ref['height']) && (!is_int($ref['height']) || $ref['height'] < 1))) throw new \InvalidArgumentException('Invalid media metadata.');
        return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function schemaJson(mixed $value): string
    {
        if (!is_string($value)) $value = json_encode($value, JSON_THROW_ON_ERROR);
        $decoded = json_decode($value, true);
        if (!is_array($decoded) || strlen($value) > 50000 || isset($decoded['script'])) throw new \InvalidArgumentException('Invalid SEO schema.');
        return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
