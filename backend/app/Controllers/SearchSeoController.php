<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\SearchSynonym;
use App\Models\SearchTerm;
use App\Models\SitemapSetting;
use App\Models\UrlRewrite;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Controller;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Response;

final class SearchSeoController extends Controller
{
    // URL Rewrites
    public function listRewrites(Request $request): Response
    {
        $q = strtolower(trim((string) $request->query('q', '')));
        $page = max(1, (int) $request->query('page', '1'));
        $perPage = max(1, min(100, (int) $request->query('per_page', '20')));
        $rows = UrlRewrite::query()->orderBy('created_at', 'desc')->get();
        $filtered = [];
        foreach ($rows as $r) {
            $row = Arr::row($r);
            if ($q !== '' && stripos(Arr::str($row, 'request_path') . Arr::str($row, 'target_path'), $q) === false) continue;
            $filtered[] = $row;
        }
        $total = count($filtered);
        $slice = array_slice($filtered, ($page - 1) * $perPage, $perPage);
        return $this->success(['data' => $slice, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage]], 'URL rewrites');
    }

    public function createRewrite(Request $request): Response
    {
        $key = $request->header('Idempotency-Key', '');
        if ($key !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) return $this->error('Invalid Idempotency-Key', 422);
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'request_path' => 'required|string',
            'target_path' => 'required|string',
            'redirect_type' => 'nullable|integer',
            'store_id' => 'nullable|string',
            'channel_id' => 'nullable|string',
        ]);
        $req = trim(Arr::requiredStr($data, 'request_path'));
        $target = trim(Arr::requiredStr($data, 'target_path'));
        $type = Arr::int($data, 'redirect_type', 301);
        if (!in_array($type, [301, 302], true)) return $this->error('Redirect type must be 301 or 302', 422);
        if (!preg_match('~^/[a-z0-9/_\-]{1,200}$~i', $req)) return $this->error('Request path must be /... 1-200 alnum/_/-', 422);
        if (!preg_match('~^(/[a-z0-9/_\-]{1,500}|https?://.+)$~i', $target)) return $this->error('Target must be /... or https://', 422);
        if ($req === $target) return $this->error('Request and target cannot be same', 422);
        if (preg_match('/(?:javascript|data|<script)/i', $req . $target) === 1) return $this->error('Paths contain forbidden content', 422);
        $store = trim(Arr::str($data, 'store_id', 'default')) ?: 'default';
        $channel = trim(Arr::str($data, 'channel_id', 'web')) ?: 'web';
        $exists = UrlRewrite::query()->where('request_path', $req)->where('store_id', $store)->where('channel_id', $channel)->first();
        if ($exists !== null) return $this->error('URL rewrite already exists for this request path', 409);
        $user = $request->user();
        $actor = is_array($user) ? Arr::str($user, 'id', 'system') : 'system';
        $newId = Ids::new();
        $payload = [
            'id' => $newId,
            'request_path' => $req,
            'target_path' => $target,
            'redirect_type' => $type,
            'store_id' => $store,
            'channel_id' => $channel,
            'created_by' => $actor,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        Database::table('url_rewrites')->insert($payload);
        $row = UrlRewrite::query()->where('id', $newId)->first();
        $out = $row ? Arr::row($row) : $payload;
        return $this->created($out, 'URL rewrite created');
    }

    public function updateRewrite(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        $row = UrlRewrite::query()->where('id', $id)->first();
        if ($row === null) return $this->error('URL rewrite not found', 404);
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'request_path' => 'nullable|string',
            'target_path' => 'nullable|string',
            'redirect_type' => 'nullable|integer',
        ]);
        $patch = [];
        if (isset($data['request_path'])) {
            $req = trim((string) $data['request_path']);
            if (!preg_match('~^/[a-z0-9/_\-]{1,200}$~i', $req)) return $this->error('Invalid request_path', 422);
            $patch['request_path'] = $req;
        }
        if (isset($data['target_path'])) {
            $target = trim((string) $data['target_path']);
            if (!preg_match('~^(/[a-z0-9/_\-]{1,500}|https?://.+)$~i', $target)) return $this->error('Invalid target_path', 422);
            $patch['target_path'] = $target;
        }
        if (isset($data['redirect_type'])) {
            $type = (int) $data['redirect_type'];
            if (!in_array($type, [301, 302], true)) return $this->error('Invalid redirect_type', 422);
            $patch['redirect_type'] = $type;
        }
        if ($patch === []) return $this->error('No changes', 422);
        $patch['updated_at'] = date('Y-m-d H:i:s');
        $row->fill($patch);
        $row->save();
        return $this->success(Arr::row($row), 'URL rewrite updated');
    }

    public function deleteRewrite(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        $row = UrlRewrite::query()->where('id', $id)->first();
        if ($row === null) return $this->error('URL rewrite not found', 404);
        $row->delete();
        return $this->success(null, 'URL rewrite deleted');
    }

    // Search Terms
    public function listTerms(Request $request): Response
    {
        $q = strtolower(trim((string) $request->query('q', '')));
        $page = max(1, (int) $request->query('page', '1'));
        $perPage = max(1, min(100, (int) $request->query('per_page', '20')));
        $sort = trim((string) $request->query('sort', ''));
        $rows = SearchTerm::query()->orderBy($sort === 'misses' ? 'misses' : 'hits', 'desc')->get();
        $filtered = [];
        foreach ($rows as $r) {
            $row = Arr::row($r);
            if ($q !== '' && stripos(Arr::str($row, 'term'), $q) === false) continue;
            $filtered[] = $row;
        }
        $total = count($filtered);
        $slice = array_slice($filtered, ($page - 1) * $perPage, $perPage);
        return $this->success(['data' => $slice, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage]], 'Search terms');
    }

    // Search Synonyms
    public function listSynonyms(Request $request): Response
    {
        $q = strtolower(trim((string) $request->query('q', '')));
        $page = max(1, (int) $request->query('page', '1'));
        $perPage = max(1, min(100, (int) $request->query('per_page', '20')));
        $rows = SearchSynonym::query()->orderBy('term', 'asc')->get();
        $filtered = [];
        foreach ($rows as $r) {
            $row = Arr::row($r);
            $syns = json_decode(Arr::str($row, 'synonyms', '[]'), true);
            $hay = Arr::str($row, 'term') . ' ' . (is_array($syns) ? implode(' ', $syns) : '');
            if ($q !== '' && stripos($hay, $q) === false) continue;
            $row['synonyms'] = is_array($syns) ? $syns : [];
            $filtered[] = $row;
        }
        $total = count($filtered);
        $slice = array_slice($filtered, ($page - 1) * $perPage, $perPage);
        return $this->success(['data' => $slice, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage]], 'Search synonyms');
    }

    public function createSynonym(Request $request): Response
    {
        $key = $request->header('Idempotency-Key', '');
        if ($key !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) return $this->error('Invalid Idempotency-Key', 422);
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'term' => 'required|string',
            'synonyms' => 'required|array',
            'store_id' => 'nullable|string',
            'channel_id' => 'nullable|string',
        ]);
        $term = strtolower(trim(Arr::requiredStr($data, 'term')));
        if (!preg_match('/^[a-z0-9 _-]{1,64}$/', $term)) return $this->error('Term must be 1-64 alnum/_/-', 422);
        $syns = $data['synonyms'];
        if (!is_array($syns) || count($syns) < 1 || count($syns) > 10) return $this->error('Synonyms must be 1-10 items', 422);
        $clean = [];
        foreach ($syns as $s) {
            if (!is_string($s)) return $this->error('Synonym must be string', 422);
            $t = strtolower(trim($s));
            if (!preg_match('/^[a-z0-9 _-]{1,64}$/', $t)) return $this->error('Invalid synonym: ' . $s, 422);
            if ($t === $term) continue;
            $clean[] = $t;
        }
        $clean = array_values(array_unique($clean));
        if ($clean === []) return $this->error('Synonyms cannot be same as term', 422);
        if (preg_match('/(?:javascript|data|<script)/i', $term . implode(' ', $clean)) === 1) return $this->error('Forbidden content', 422);
        $store = trim(Arr::str($data, 'store_id', 'default')) ?: 'default';
        $channel = trim(Arr::str($data, 'channel_id', 'web')) ?: 'web';
        $exists = SearchSynonym::query()->where('term', $term)->where('store_id', $store)->where('channel_id', $channel)->first();
        if ($exists !== null) return $this->error('Synonym for term already exists', 409);
        $newId = Ids::new();
        $payload = [
            'id' => $newId,
            'term' => $term,
            'synonyms' => json_encode($clean, JSON_THROW_ON_ERROR),
            'store_id' => $store,
            'channel_id' => $channel,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        Database::table('search_synonyms')->insert($payload);
        $row = SearchSynonym::query()->where('id', $newId)->first();
        $out = $row ? Arr::row($row) : $payload;
        $out['synonyms'] = $clean;
        return $this->created($out, 'Synonym created');
    }

    public function updateSynonym(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        $row = SearchSynonym::query()->where('id', $id)->first();
        if ($row === null) return $this->error('Synonym not found', 404);
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'term' => 'nullable|string',
            'synonyms' => 'nullable|array',
        ]);
        $patch = [];
        if (isset($data['term'])) {
            $term = strtolower(trim((string) $data['term']));
            if (!preg_match('/^[a-z0-9 _-]{1,64}$/', $term)) return $this->error('Invalid term', 422);
            $patch['term'] = $term;
        }
        if (isset($data['synonyms'])) {
            $syns = $data['synonyms'];
            if (!is_array($syns) || count($syns) < 1 || count($syns) > 10) return $this->error('Synonyms must be 1-10', 422);
            $clean = [];
            foreach ($syns as $s) {
                if (!is_string($s)) return $this->error('Invalid synonym', 422);
                $t = strtolower(trim($s));
                if (!preg_match('/^[a-z0-9 _-]{1,64}$/', $t)) return $this->error('Invalid synonym', 422);
                $clean[] = $t;
            }
            $patch['synonyms'] = json_encode(array_values(array_unique($clean)), JSON_THROW_ON_ERROR);
        }
        if ($patch === []) return $this->error('No changes', 422);
        $patch['updated_at'] = date('Y-m-d H:i:s');
        $row->fill($patch);
        $row->save();
        $out = Arr::row($row);
        $out['synonyms'] = json_decode(Arr::str($out, 'synonyms', '[]'), true);
        return $this->success($out, 'Synonym updated');
    }

    public function deleteSynonym(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        $row = SearchSynonym::query()->where('id', $id)->first();
        if ($row === null) return $this->error('Synonym not found', 404);
        $row->delete();
        return $this->success(null, 'Synonym deleted');
    }

    // Sitemaps
    public function getSitemaps(Request $request): Response
    {
        $store = trim((string) $request->header('X-Store-Id', 'default')) ?: 'default';
        $channel = trim((string) $request->header('X-Channel-Id', 'web')) ?: 'web';
        $row = SitemapSetting::query()->where('store_id', $store)->where('channel_id', $channel)->first();
        $defaults = ['products' => ['frequency' => 'daily', 'priority' => 0.8], 'categories' => ['frequency' => 'weekly', 'priority' => 0.6], 'cms_pages' => ['frequency' => 'weekly', 'priority' => 0.5], 'brands' => ['frequency' => 'monthly', 'priority' => 0.4], 'images_enabled' => true];
        if ($row === null) return $this->success(['settings' => $defaults, 'version' => 0], 'Sitemap settings');
        $settings = json_decode(Arr::str(Arr::row($row), 'settings_json', '{}'), true);
        if (!is_array($settings)) $settings = $defaults;
        return $this->success(['settings' => $settings, 'version' => Arr::int(Arr::row($row), 'version', 1)], 'Sitemap settings');
    }

    public function updateSitemaps(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'products' => 'nullable|array',
            'categories' => 'nullable|array',
            'cms_pages' => 'nullable|array',
            'brands' => 'nullable|array',
            'images_enabled' => 'nullable|boolean',
        ]);
        $allowedFreq = ['always','hourly','daily','weekly','monthly','yearly','never'];
        $settings = [];
        foreach (['products','categories','cms_pages','brands'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                $freq = Arr::str($data[$k], 'frequency', 'weekly');
                $prioValue = $data[$k]['priority'] ?? '0.5';
                $prio = is_numeric($prioValue) ? (float) $prioValue : -1.0;
                if (!in_array($freq, $allowedFreq, true)) return $this->error("Invalid frequency for $k", 422);
                if ($prio < 0 || $prio > 1) return $this->error("Invalid priority for $k", 422);
                $settings[$k] = ['frequency' => $freq, 'priority' => $prio];
            } else {
                $settings[$k] = ['frequency' => 'weekly', 'priority' => 0.5];
            }
        }
        $settings['images_enabled'] = (bool) ($data['images_enabled'] ?? true);
        $store = trim((string) $request->header('X-Store-Id', 'default')) ?: 'default';
        $channel = trim((string) $request->header('X-Channel-Id', 'web')) ?: 'web';
        $existing = SitemapSetting::query()->where('store_id', $store)->where('channel_id', $channel)->first();
        if ($existing !== null) {
            $ver = Arr::int(Arr::row($existing), 'version', 1) + 1;
            $existing->setAttribute('settings_json', json_encode($settings, JSON_THROW_ON_ERROR));
            $existing->setAttribute('version', $ver);
            $existing->setAttribute('updated_at', date('Y-m-d H:i:s'));
            $existing->save();
        } else {
            SitemapSetting::create([
                'id' => Ids::new(),
                'store_id' => $store,
                'channel_id' => $channel,
                'settings_json' => json_encode($settings, JSON_THROW_ON_ERROR),
                'version' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        return $this->success(['settings' => $settings], 'Sitemap settings saved');
    }

    public function generateSitemaps(Request $request): Response
    {
        $key = $request->header('Idempotency-Key', '');
        if ($key !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) return $this->error('Invalid Idempotency-Key', 422);
        // For MVP, just return success without actual file generation (would write to storage/app/sitemap)
        $store = trim((string) $request->header('X-Store-Id', 'default')) ?: 'default';
        $channel = trim((string) $request->header('X-Channel-Id', 'web')) ?: 'web';
        return $this->success(['store_id' => $store, 'channel_id' => $channel, 'generated_at' => date('c'), 'files' => ['/sitemap.xml', '/sitemap-products.xml']], 'Sitemaps generated');
    }
}
