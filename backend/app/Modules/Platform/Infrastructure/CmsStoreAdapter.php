<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\CmsBlock;
use App\Models\CmsPage;
use App\Modules\Cms\Contracts\CmsStore;
use App\Support\Arr;
use App\Support\Ids;
use App\Models\CmsRevision;
use App\Models\CmsMenu;
use Siro\Core\Cache;
use App\Support\CommerceScope;
use Siro\Core\Audit;
use Siro\Core\Database;
use App\Modules\Cms\Domain\CmsConflict;

final class CmsStoreAdapter implements CmsStore
{
    public function list(?CommerceScope $scope = null): array { $query = CmsPage::query(); if ($scope !== null) $query->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)->where('locale', $scope->locale)->where('currency', $scope->currency); return array_map(static fn ($row): array => Arr::row($row), $query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->get()); }
    public function published(string $slug, string $locale, ?CommerceScope $scope = null): ?array
    {
        $key = $this->publishedKey($slug, $locale, $scope);
        $cached = Cache::remember($key, 60, function () use ($slug, $locale, $scope): ?array {
            $query = CmsPage::with('blocks')->where('slug', $slug)->where('locale', $locale)->where('status', 'published');
            if ($scope !== null) $query->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)->where('currency', $scope->currency);
            $page = $query->first();
            $pageRow = $page === null ? null : Arr::row($page);
            if ($page !== null && Arr::str($pageRow ?? [], 'scheduled_at') !== '' && Arr::str($pageRow ?? [], 'scheduled_at') > date('Y-m-d H:i:s')) $page = null;
            if ($page === null) return null;
            $out = Arr::row($page);
            $blocks = $page->getRelation('blocks');
            $publishedBlocks = [];
            if (is_array($blocks)) {
                foreach ($blocks as $block) {
                    if (!$block instanceof CmsBlock) continue;
                    $blockRow = Arr::row($block);
                    if (Arr::str($blockRow, 'status') === 'published') $publishedBlocks[] = $blockRow;
                }
            }
            $out['blocks'] = array_map(
                static fn (array $row): array => $row,
                $publishedBlocks,
            );
            $out['media'] = $this->decodeList($out['media_json'] ?? null);
            unset($out['media_json']);
            $out['seo'] = ['title' => $out['seo_title'] ?? null, 'description' => $out['seo_description'] ?? null, 'canonical' => $out['canonical'] ?? null, 'robots' => $out['seo_robots'] ?? 'index,follow', 'schema' => $this->decodeList($out['seo_schema_json'] ?? null)];
            unset($out['seo_title'], $out['seo_description'], $out['canonical'], $out['seo_robots'], $out['seo_schema_json']);
            foreach ($out['blocks'] as &$block) { $block['media'] = $this->decodeList($block['media_json'] ?? null); unset($block['media_json']); }
            usort($out['blocks'], static fn (array $left, array $right): int => Arr::int($left, 'sort') <=> Arr::int($right, 'sort'));
            return $out;
        });
        return is_array($cached) ? Arr::row($cached) : null;
    }
    public function preview(string $id, ?CommerceScope $scope = null): ?array
    {
        $query = CmsPage::with('blocks')->where('id', $id); if ($scope !== null) $query->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)->where('locale', $scope->locale)->where('currency', $scope->currency); $page = $query->first();
        if ($page === null) return null;
        $out = Arr::row($page); $blocks = [];
        $relation = $page->getRelation('blocks');
        if (is_array($relation)) foreach ($relation as $block) { $row = Arr::row($block); $row['media'] = $this->decodeList($row['media_json'] ?? null); unset($row['media_json']); $blocks[] = $row; }
        $out['blocks'] = $blocks; return $out;
    }
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(array $data): array
    {
        $result = $this->createRow(CmsPage::class, $data);
        $this->revision('page', $result, 'created');
        $this->forgetPublished($result['slug'] ?? null, $result['locale'] ?? null, $this->scopeFromRow($result));
        return $result;
    }
    /** @param array<string, mixed> $data @return array<string, mixed>|null */
    public function update(string $id, array $data, ?CommerceScope $scope = null): ?array
    {
        $query = CmsPage::query()->where('id', $id); if ($scope !== null) $query->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)->where('locale', $scope->locale)->where('currency', $scope->currency); $before = $query->first();
        if ($before === null) return null;
        $result = $this->updateRow(CmsPage::class, $id, $data);
        $this->forgetPublished($before->slug, $before->locale, $this->scopeFromRow(Arr::row($before)));
        if ($result !== null) {
            $this->revision('page', $result, 'updated');
            $this->forgetPublished($result['slug'] ?? null, $result['locale'] ?? null, $this->scopeFromRow($result));
        }
        return $result;
    }
    /** @param array<string, mixed> $data @return array<string, mixed>|null */
    public function addBlock(string $pageId, array $data, ?CommerceScope $scope = null): ?array
    {
        $query = CmsPage::query()->where('id', $pageId); if ($scope !== null) $query->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)->where('locale', $scope->locale)->where('currency', $scope->currency); $page = $query->first();
        if ($page === null) return null;
        $result = $this->createRow(CmsBlock::class, $data + ['page_id' => $pageId]);
        $this->revision('block', $result, 'created');
        $this->forgetPublished($page->slug, $page->locale, $this->scopeFromRow(Arr::row($page)));
        return $result;
    }
    /** @param array<string, mixed> $data @return array<string, mixed>|null */
    public function updateBlock(string $id, array $data, ?CommerceScope $scope = null): ?array
    {
        $block = CmsBlock::find($id);
        if ($block === null) return null;
        $page = CmsPage::find(Arr::str($block->toArray(), 'page_id'));
        if ($scope !== null && ($page === null || Arr::str(Arr::row($page), 'store_id') !== $scope->storeId || Arr::str(Arr::row($page), 'channel_id') !== $scope->channelId || Arr::str(Arr::row($page), 'locale') !== $scope->locale || Arr::str(Arr::row($page), 'currency') !== $scope->currency)) return null;
        $result = $this->updateRow(CmsBlock::class, $id, $data);
        if ($result !== null) $this->revision('block', $result, 'updated');
        if ($page !== null) $this->forgetPublished($page->slug, $page->locale, $this->scopeFromRow(Arr::row($page)));
        return $result;
    }

    public function revisions(string $entityType, string $entityId, CommerceScope $scope): array
    {
        return array_map(static fn ($row): array => Arr::row($row), CmsRevision::query()->where('entity_type', $entityType)->where('entity_id', $entityId)->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)->where('locale', $scope->locale)->where('currency', $scope->currency)->orderBy('version', 'desc')->limit(50)->get());
    }

    public function diff(string $entityType, string $entityId, string $fromRevision, string $toRevision, CommerceScope $scope): array
    {
        $rows = CmsRevision::query()->where('entity_type', $entityType)->where('entity_id', $entityId)->whereIn('id', [$fromRevision, $toRevision])->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)->where('locale', $scope->locale)->where('currency', $scope->currency)->get();
        $snapshots = [];
        foreach ($rows as $row) { $decoded = json_decode(Arr::str(Arr::row($row), 'snapshot_json'), true); if (is_array($decoded)) $snapshots[Arr::str(Arr::row($row), 'id')] = $decoded; }
        if (!isset($snapshots[$fromRevision], $snapshots[$toRevision])) throw new \InvalidArgumentException('CMS revisions must belong to the same commerce scope.');
        $changes = [];
        $keys = array_unique(array_merge(array_keys($snapshots[$fromRevision]), array_keys($snapshots[$toRevision])));
        foreach ($keys as $key) if (($snapshots[$fromRevision][$key] ?? null) !== ($snapshots[$toRevision][$key] ?? null)) $changes[] = ['field' => $key, 'from' => $snapshots[$fromRevision][$key] ?? null, 'to' => $snapshots[$toRevision][$key] ?? null];
        return ['entity_type' => $entityType, 'entity_id' => $entityId, 'from_revision' => $fromRevision, 'to_revision' => $toRevision, 'changes' => $changes];
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|null */
    public function autosave(string $id, array $data, CommerceScope $scope, string $actorId, string $idempotencyKey, ?int $expectedVersion = null): ?array
    {
        /** @var array<string, mixed>|null $result */
        $result = Database::transaction(function () use ($id, $data, $scope, $actorId, $idempotencyKey, $expectedVersion): ?array {
            $page = $this->scopedPage($id, $scope); if ($page === null) return null;
            if ($this->replayed($id, $idempotencyKey) !== null) return Arr::row($page);
            $this->assertVersion($page, $expectedVersion);
            $result = $this->updateRow(CmsPage::class, $id, $data);
            if ($result !== null) $this->revision('page', $result, 'autosaved', $actorId, $idempotencyKey);
            return $result;
        });
        return $result;
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|null */
    public function merge(string $id, string $revisionId, array $data, CommerceScope $scope, string $actorId, string $idempotencyKey, ?int $expectedVersion = null): ?array
    {
        /** @var array<string, mixed>|null $result */
        $result = Database::transaction(function () use ($id, $revisionId, $data, $scope, $actorId, $idempotencyKey, $expectedVersion): ?array {
            $page = $this->scopedPage($id, $scope); if ($page === null) return null;
            if ($this->replayed($id, $idempotencyKey) !== null) return Arr::row($page);
            $this->assertVersion($page, $expectedVersion);
            $revision = CmsRevision::query()->where('id', $revisionId)->where('entity_type', 'page')->where('entity_id', $id)->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)->where('locale', $scope->locale)->where('currency', $scope->currency)->first();
            if ($revision === null) throw new \InvalidArgumentException('CMS revision not found in commerce scope');
            $snapshot = json_decode(Arr::str(Arr::row($revision), 'snapshot_json'), true);
            if (!is_array($snapshot)) throw new \RuntimeException('CMS revision snapshot is invalid');
            $fields = ['slug', 'title', 'body', 'status', 'published_at', 'scheduled_at', 'canonical', 'seo_title', 'seo_description', 'seo_robots', 'seo_schema_json', 'media_json'];
            $merged = []; foreach ($fields as $field) if (array_key_exists($field, $snapshot)) $merged[$field] = $snapshot[$field];
            $result = $this->updateRow(CmsPage::class, $id, $data + $merged);
            if ($result !== null) $this->revision('page', $result, 'merged', $actorId, $idempotencyKey);
            return $result;
        });
        return $result;
    }

    public function publish(string $id, CommerceScope $scope, string $actorId, string $idempotencyKey): ?array
    {
        $result = Database::transaction(function () use ($id, $scope, $actorId, $idempotencyKey): ?array {
            $page = CmsPage::query()->where('id', $id)->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)->where('locale', $scope->locale)->where('currency', $scope->currency)->first();
            if ($page === null) return null;
            $replayed = $this->replayed($id, $idempotencyKey);
            if ($replayed !== null) return Arr::row($page);
            $now = date('Y-m-d H:i:s');
            $page->update(['status' => 'published', 'published_at' => $now, 'scheduled_at' => null, 'version' => Arr::int(Arr::row($page), 'version', 1) + 1]);
            $result = Arr::row($page);
            $this->revision('page', $result, 'published', $actorId, $idempotencyKey);
            $this->forgetPublished($result['slug'] ?? null, $result['locale'] ?? null, $scope);
            Audit::log('commerce.cms.published', ['page_id' => $id, 'actor_id' => $actorId, 'scope' => $scope->key(), 'idempotency_key' => $idempotencyKey]);
            return $result;
        });
        return is_array($result) ? Arr::row($result) : null;
    }

    public function restore(string $id, string $revisionId, CommerceScope $scope, string $actorId, string $idempotencyKey): ?array
    {
        $result = Database::transaction(function () use ($id, $revisionId, $scope, $actorId, $idempotencyKey): ?array {
            $page = CmsPage::query()->where('id', $id)->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)->where('locale', $scope->locale)->where('currency', $scope->currency)->first();
            if ($page === null) return null;
            $revision = CmsRevision::query()->where('id', $revisionId)->where('entity_type', 'page')->where('entity_id', $id)->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)->where('locale', $scope->locale)->where('currency', $scope->currency)->first();
            if ($revision === null) throw new \InvalidArgumentException('CMS revision not found in commerce scope');
            if ($this->replayed($id, $idempotencyKey) !== null) return Arr::row($page);
            $snapshot = json_decode(Arr::str(Arr::row($revision), 'snapshot_json'), true);
            if (!is_array($snapshot)) throw new \RuntimeException('CMS revision snapshot is invalid');
            $fields = ['slug', 'title', 'body', 'status', 'published_at', 'scheduled_at', 'canonical', 'seo_title', 'seo_description', 'seo_robots', 'seo_schema_json', 'media_json'];
            $data = [];
            foreach ($fields as $field) if (array_key_exists($field, $snapshot)) $data[$field] = $snapshot[$field];
            $data['store_id'] = $scope->storeId; $data['channel_id'] = $scope->channelId; $data['locale'] = $scope->locale; $data['currency'] = $scope->currency;
            $data['version'] = Arr::int(Arr::row($page), 'version', 1) + 1;
            $page->update($data);
            $result = Arr::row($page);
            $this->revision('page', $result, 'restored', $actorId, $idempotencyKey);
            $this->forgetPublished($result['slug'] ?? null, $result['locale'] ?? null, $scope);
            Audit::log('commerce.cms.restored', ['page_id' => $id, 'revision_id' => $revisionId, 'actor_id' => $actorId, 'scope' => $scope->key(), 'idempotency_key' => $idempotencyKey]);
            return $result;
        });
        return is_array($result) ? Arr::row($result) : null;
    }

    public function menu(string $key, string $locale): ?array
    {
        $menu = CmsMenu::with('items')->where('key', $key)->where('locale', $locale)->where('status', 'published')->first();
        if ($menu === null) return null;
        $out = Arr::row($menu); $items = [];
        $relation = $menu->getRelation('items');
        if (is_array($relation)) foreach ($relation as $item) { $row = Arr::row($item); if (Arr::str($row, 'status') === 'published') $items[] = $row; }
        usort($items, static fn (array $a, array $b): int => Arr::int($a, 'sort') <=> Arr::int($b, 'sort'));
        $out['items'] = $items; return $out;
    }

    public function publishScheduled(string $now): int
    {
        $count = 0;
        foreach (CmsPage::query()->where('status', 'scheduled')->where('scheduled_at', '<=', $now)->limit(100)->get() as $page) { $page->update(['status' => 'published', 'published_at' => $now, 'version' => Arr::int(Arr::row($page), 'version', 1) + 1]); $this->forgetPublished($page->slug, $page->locale, $this->scopeFromRow(Arr::row($page))); $count++; }
        return $count;
    }

    private function publishedKey(?string $slug, ?string $locale, ?CommerceScope $scope = null): string
    {
        return 'cms:published:' . ($scope?->key() ?? 'legacy') . ':' . (string) $locale . ':' . (string) $slug;
    }

    private function forgetPublished(mixed $slug, mixed $locale, ?CommerceScope $scope = null): void
    {
        if (is_string($slug) && is_string($locale)) {
            Cache::forget($this->publishedKey($slug, $locale, $scope));
            if ($scope !== null) Cache::forget($this->publishedKey($slug, $locale));
        }
    }
    /**
     * @param class-string<\Siro\Core\Model> $class
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function createRow(string $class, array $data): array { $id = Ids::new(); $row = $class::create(['id' => $id] + $data)->toArray(); $row['id'] = $id; return $row; }
    /**
     * @param class-string<\Siro\Core\Model> $class
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function updateRow(string $class, string $id, array $data): ?array { $model = $class::find($id); if ($model === null) return null; $row = Arr::row($model); $data['version'] = Arr::int($row, 'version', 1) + 1; $model->update($data); return $model->toArray(); }
    /** @param array<string, mixed> $row */
    private function revision(string $type, array $row, string $action, ?string $actorId = null, ?string $idempotencyKey = null): void
    {
        $id = Arr::str($row, 'id'); if ($id === '') return;
        $version = max(1, Arr::int($row, 'version', 1));
        CmsRevision::create(['id' => Ids::new(), 'entity_type' => $type, 'entity_id' => $id, 'version' => $version, 'snapshot_json' => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'action' => $action, 'actor_id' => $actorId, 'idempotency_key' => $idempotencyKey, 'store_id' => Arr::str($row, 'store_id', 'default'), 'channel_id' => Arr::str($row, 'channel_id', 'default'), 'locale' => Arr::str($row, 'locale', 'en'), 'currency' => Arr::str($row, 'currency', 'VND'), 'created_at' => date('Y-m-d H:i:s')]);
    }

    /** @return array<string, mixed>|null */
    private function replayed(string $entityId, string $key): ?array
    {
        $row = CmsRevision::query()->where('idempotency_key', $key)->first();
        if ($row === null) return null;
        if (Arr::str(Arr::row($row), 'entity_id') !== $entityId) throw new \InvalidArgumentException('Idempotency-Key was reused for another CMS page');
        return Arr::row($row);
    }
    private function scopedPage(string $id, CommerceScope $scope): ?CmsPage
    {
        $page = CmsPage::query()->where('id', $id)->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)->where('locale', $scope->locale)->where('currency', $scope->currency)->first();
        return $page instanceof CmsPage ? $page : null;
    }
    private function assertVersion(CmsPage $page, ?int $expectedVersion): void
    {
        if ($expectedVersion !== null && Arr::int(Arr::row($page), 'version', 1) !== $expectedVersion) throw new CmsConflict('CMS page changed since it was loaded.', ['current_version' => Arr::int(Arr::row($page), 'version', 1), 'expected_version' => $expectedVersion]);
    }
    /** @param array<string, mixed> $row */
    private function scopeFromRow(array $row): ?CommerceScope
    {
        $store = Arr::str($row, 'store_id'); $channel = Arr::str($row, 'channel_id'); $locale = Arr::str($row, 'locale'); $currency = Arr::str($row, 'currency');
        if ($store === '' || $channel === '' || $locale === '' || $currency === '') return null;
        return new CommerceScope($store, $channel, $locale, $currency);
    }
    /** @return array<int, mixed> */
    private function decodeList(mixed $value): array { if (!is_string($value) || $value === '') return []; $decoded = json_decode($value, true); return is_array($decoded) ? array_values($decoded) : []; }
}
