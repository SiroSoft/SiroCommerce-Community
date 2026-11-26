<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Tag;
use App\Support\Arr;
use App\Support\Skeleton;
use Siro\Core\Str;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Admin-console tag CRUD (skeleton API dialect).
 */
final class SkeletonTagController extends Controller
{
    public function index(Request $request): Response
    {
        $page = max($request->queryInt('page', 1), 1);
        $perPage = min(max($request->queryInt('per_page', 20), 1), 100);
        $search = trim($request->queryString('search', '') ?: $request->queryString('q', ''));
        $query = Tag::query()->orderBy('id', 'asc');
        if ($search !== '') $query->whereRaw('(name LIKE ? OR slug LIKE ?)', ['%' . $search . '%', '%' . $search . '%']);
        $total = $query->count();
        $rows = $query->limit($perPage)->offset(($page - 1) * $perPage)->get();
        $data = array_map(static fn ($model): array => self::row($model->toArray()), $rows);
        return $this->paginated($data, ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => (int) max(1, ceil($total / $perPage))], 'Tags list');
    }

    public function show(Request $request): Response
    {
        $row = Tag::find((int) Arr::requiredParam($request, 'id'));
        if ($row === null) return $this->error('Tag not found', 404);
        return $this->success(self::row($row->toArray()), 'Tag detail');
    }

    public function store(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['name' => 'required|string', 'slug' => 'nullable|string', 'color' => 'nullable|string']);
        $name = trim(Arr::requiredStr($data, 'name'));
        if ($name === '') return $this->error('Name is required', 422, ['name' => ['Name is required']]);
        $slug = Arr::str($data, 'slug', Str::slug($name));
        if (Tag::query()->where('slug', $slug)->first() !== null) return $this->error('Slug already taken', 422, ['slug' => ['Slug already taken']]);
        $row = Tag::create(['name' => $name, 'slug' => $slug, 'color' => Arr::str($data, 'color', '')]);
        return $this->created(self::row($row->toArray()), 'Tag created');
    }

    public function update(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['name' => 'nullable|string', 'slug' => 'nullable|string', 'color' => 'nullable|string']);
        $row = Tag::find((int) Arr::requiredParam($request, 'id'));
        if ($row === null) return $this->error('Tag not found', 404);
        $patch = [];
        if (isset($data['name']) && trim((string) $data['name']) !== '') $patch['name'] = trim((string) $data['name']);
        if (isset($data['slug']) && trim((string) $data['slug']) !== '') {
            $slug = trim((string) $data['slug']);
            $taken = Tag::query()->where('slug', $slug)->first();
            if ($taken !== null && (int) $taken->getAttribute('id') !== (int) $row->getAttribute('id')) return $this->error('Slug already taken', 422, ['slug' => ['Slug already taken']]);
            $patch['slug'] = $slug;
        }
        if (isset($data['color']) && is_string($data['color'])) $patch['color'] = $data['color'];
        if ($patch !== []) $row->update($patch);
        $fresh = Tag::find((int) $row->getAttribute('id'));
        return $this->success(self::row($fresh === null ? [] : $fresh->toArray()), 'Tag updated');
    }

    public function destroy(Request $request): Response
    {
        $row = Tag::find((int) Arr::requiredParam($request, 'id'));
        if ($row === null) return $this->error('Tag not found', 404);
        $row->delete();
        return $this->noContent();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function row(array $row): array
    {
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null, 'name' => Arr::str($row, 'name'), 'slug' => Arr::str($row, 'slug'),
            'color' => Arr::str($row, 'color', '') === '' ? null : Arr::str($row, 'color'),
            'created_at' => Skeleton::date($row['created_at'] ?? null), 'updated_at' => Skeleton::date($row['updated_at'] ?? null),
        ];
    }
}
