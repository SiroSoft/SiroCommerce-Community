<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Category;
use App\Support\Arr;
use App\Support\Ids;
use App\Support\Skeleton;
use Siro\Core\Database;
use Siro\Core\Str;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Admin-console category CRUD (skeleton API dialect, numeric ids).
 */
final class SkeletonCategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $page = max($request->queryInt('page', 1), 1);
        $perPage = min(max($request->queryInt('per_page', 20), 1), 100);
        $search = trim($request->queryString('search', '') ?: $request->queryString('q', ''));
        $query = Category::query()->orderBy('sort', 'asc')->orderBy('id', 'asc');
        if ($search !== '') $query->whereRaw('(name LIKE ? OR slug LIKE ?)', ['%' . $search . '%', '%' . $search . '%']);
        $total = $query->count();
        $rows = $query->limit($perPage)->offset(($page - 1) * $perPage)->get();
        $data = array_map(static fn ($model): array => self::row($model->toArray()), $rows);
        return $this->paginated($data, ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => (int) max(1, ceil($total / $perPage))], 'Categories list');
    }

    public function show(Request $request): Response
    {
        $row = self::find((string) Arr::requiredParam($request, 'id'));
        if ($row === null) return $this->error('Category not found', 404);
        return $this->success(self::row($row), 'Category detail');
    }

    public function store(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => 'required|string', 'slug' => 'nullable|string', 'description' => 'nullable|string',
            'color' => 'nullable|string', 'icon' => 'nullable|string', 'parent_id' => 'nullable|integer',
            'sort_order' => 'nullable|integer', 'is_active' => 'nullable|boolean',
        ]);
        $name = trim(Arr::requiredStr($data, 'name'));
        if ($name === '') return $this->error('Name is required', 422, ['name' => ['Name is required']]);
        $slug = Arr::str($data, 'slug', Str::slug($name));
        if (Category::query()->where('slug', $slug)->first() !== null) return $this->error('Slug already taken', 422, ['slug' => ['Slug already taken']]);
        $parentId = isset($data['parent_id']) ? Skeleton::resolveId('categories', (int) $data['parent_id']) : null;
        if (isset($data['parent_id']) && $parentId === null) return $this->error('Parent category not found', 422, ['parent_id' => ['Parent category not found']]);
        // Raw insert: Model::save() overwrites string PKs with lastInsertId on SQLite.
        $id = Ids::new();
        $sortOrder = isset($data['sort_order']) ? (int) $data['sort_order'] : 0;
        Database::connection()->prepare('INSERT INTO categories (id,parent_id,name,slug,`sort`,description,color,icon,sort_order,is_active) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([
            $id, $parentId, $name, $slug, $sortOrder,
            Arr::str($data, 'description', ''), Arr::str($data, 'color', ''), Arr::str($data, 'icon', ''),
            $sortOrder, isset($data['is_active']) ? ((bool) $data['is_active'] ? 1 : 0) : 1,
        ]);
        $created = Category::find($id);
        if ($created === null) return $this->error('Category could not be created', 500);
        return $this->created(self::row($created->toArray()), 'Category created');
    }

    public function update(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => 'nullable|string', 'slug' => 'nullable|string', 'description' => 'nullable|string',
            'color' => 'nullable|string', 'icon' => 'nullable|string', 'parent_id' => 'nullable|integer',
            'sort_order' => 'nullable|integer', 'is_active' => 'nullable|boolean',
        ]);
        $id = self::resolve((string) Arr::requiredParam($request, 'id'));
        if ($id === null) return $this->error('Category not found', 404);
        $row = Category::find($id);
        if ($row === null) return $this->error('Category not found', 404);
        $patch = [];
        if (isset($data['name']) && trim((string) $data['name']) !== '') $patch['name'] = trim((string) $data['name']);
        if (isset($data['slug']) && trim((string) $data['slug']) !== '') {
            $slug = trim((string) $data['slug']);
            $taken = Category::query()->where('slug', $slug)->first();
            if ($taken !== null && (string) $taken->getAttribute('id') !== $id) return $this->error('Slug already taken', 422, ['slug' => ['Slug already taken']]);
            $patch['slug'] = $slug;
        }
        foreach (['description', 'color', 'icon'] as $field) if (isset($data[$field]) && is_string($data[$field])) $patch[$field] = $data[$field];
        if (isset($data['sort_order'])) { $patch['sort_order'] = (int) $data['sort_order']; $patch['sort'] = (int) $data['sort_order']; }
        if (isset($data['is_active'])) $patch['is_active'] = (bool) $data['is_active'] ? 1 : 0;
        if (array_key_exists('parent_id', $data)) {
            if ($data['parent_id'] === null) { $patch['parent_id'] = null; }
            else { $parentId = Skeleton::resolveId('categories', (int) $data['parent_id']); if ($parentId === null || $parentId === $id) return $this->error('Parent category not found', 422, ['parent_id' => ['Parent category not found']]); $patch['parent_id'] = $parentId; }
        }
        if ($patch !== []) $row->update($patch);
        $fresh = Category::find($id);
        return $this->success(self::row($fresh === null ? [] : $fresh->toArray()), 'Category updated');
    }

    public function destroy(Request $request): Response
    {
        $id = self::resolve((string) Arr::requiredParam($request, 'id'));
        if ($id === null) return $this->error('Category not found', 404);
        $row = Category::find($id);
        if ($row === null) return $this->error('Category not found', 404);
        $row->delete();
        return $this->noContent();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function row(array $row): array
    {
        $id = Arr::str($row, 'id');
        $parentId = Arr::str($row, 'parent_id', '');
        return [
            'id' => Skeleton::num($id), 'name' => Arr::str($row, 'name'), 'slug' => Arr::str($row, 'slug'),
            'description' => Arr::str($row, 'description', ''), 'color' => Arr::str($row, 'color', '') === '' ? null : Arr::str($row, 'color'),
            'icon' => Arr::str($row, 'icon', '') === '' ? null : Arr::str($row, 'icon'),
            'parent_id' => $parentId === '' ? null : Skeleton::num($parentId),
            'sort_order' => (int) ($row['sort_order'] ?? $row['sort'] ?? 0),
            'is_active' => (int) ($row['is_active'] ?? 1) === 1,
            'created_at' => Skeleton::date($row['created_at'] ?? null), 'updated_at' => Skeleton::date($row['updated_at'] ?? null),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $idOrNum): ?array
    {
        $id = self::resolve($idOrNum);
        if ($id === null) return null;
        $row = Category::find($id);
        if ($row === null) return null;
        /** @var array<string, mixed> $out */
        $out = $row->toArray();
        return $out;
    }

    private static function resolve(string $idOrNum): ?string
    {
        if (!ctype_digit($idOrNum)) {
            $row = Category::find($idOrNum);
            return $row === null ? null : $idOrNum;
        }
        return Skeleton::resolveId('categories', (int) $idOrNum);
    }
}
