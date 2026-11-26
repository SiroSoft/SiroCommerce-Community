<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Post;
use App\Support\Arr;
use App\Support\Skeleton;
use Siro\Core\Str;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Admin-console post CRUD (skeleton API dialect).
 */
final class SkeletonPostController extends Controller
{
    public function index(Request $request): Response
    {
        $page = max($request->queryInt('page', 1), 1);
        $perPage = min(max($request->queryInt('per_page', 20), 1), 100);
        $search = trim($request->queryString('search', '') ?: $request->queryString('q', ''));
        $query = Post::query()->orderBy('id', 'desc');
        if ($search !== '') $query->whereRaw('(title LIKE ? OR slug LIKE ?)', ['%' . $search . '%', '%' . $search . '%']);
        $status = trim($request->queryString('status', ''));
        if ($status !== '') $query->where('status', $status);
        $total = $query->count();
        $rows = $query->limit($perPage)->offset(($page - 1) * $perPage)->get();
        $data = array_map(static fn ($model): array => self::row($model->toArray()), $rows);
        return $this->paginated($data, ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => (int) max(1, ceil($total / $perPage))], 'Posts list');
    }

    public function show(Request $request): Response
    {
        $row = Post::find((int) Arr::requiredParam($request, 'id'));
        if ($row === null) return $this->error('Post not found', 404);
        return $this->success(self::row($row->toArray()), 'Post detail');
    }

    public function store(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'title' => 'required|string', 'slug' => 'nullable|string', 'excerpt' => 'nullable|string', 'content' => 'nullable|string',
            'image' => 'nullable|string', 'cover_image' => 'nullable|string', 'status' => 'nullable|string', 'featured' => 'nullable|boolean',
            'category_id' => 'nullable|integer', 'author_name' => 'nullable|string', 'published_at' => 'nullable|string',
        ]);
        $title = trim(Arr::requiredStr($data, 'title'));
        if ($title === '') return $this->error('Title is required', 422, ['title' => ['Title is required']]);
        $slug = Arr::str($data, 'slug', Str::slug($title));
        if (Post::query()->where('slug', $slug)->first() !== null) return $this->error('Slug already taken', 422, ['slug' => ['Slug already taken']]);
        $status = Arr::str($data, 'status', 'draft');
        if (!in_array($status, ['draft', 'published', 'archived'], true)) $status = 'draft';
        $row = Post::create([
            'title' => $title, 'slug' => $slug, 'excerpt' => Arr::str($data, 'excerpt', ''), 'content' => Arr::str($data, 'content', ''),
            'image' => Arr::str($data, 'image', '') !== '' ? Arr::str($data, 'image') : Arr::str($data, 'cover_image', ''),
            'status' => $status, 'featured' => isset($data['featured']) && (bool) $data['featured'] ? 1 : 0,
            'category_id' => isset($data['category_id']) ? (string) (int) $data['category_id'] : null,
            'author_name' => Arr::str($data, 'author_name', ''),
            'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
        ]);
        return $this->created(self::row($row->toArray()), 'Post created');
    }

    public function update(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'title' => 'nullable|string', 'slug' => 'nullable|string', 'excerpt' => 'nullable|string', 'content' => 'nullable|string',
            'image' => 'nullable|string', 'cover_image' => 'nullable|string', 'status' => 'nullable|string', 'featured' => 'nullable|boolean',
            'category_id' => 'nullable|integer', 'author_name' => 'nullable|string', 'published_at' => 'nullable|string',
        ]);
        $row = Post::find((int) Arr::requiredParam($request, 'id'));
        if ($row === null) return $this->error('Post not found', 404);
        $patch = [];
        if (isset($data['title']) && trim((string) $data['title']) !== '') $patch['title'] = trim((string) $data['title']);
        if (isset($data['slug']) && trim((string) $data['slug']) !== '') {
            $slug = trim((string) $data['slug']);
            $taken = Post::query()->where('slug', $slug)->first();
            if ($taken !== null && (int) $taken->getAttribute('id') !== (int) $row->getAttribute('id')) return $this->error('Slug already taken', 422, ['slug' => ['Slug already taken']]);
            $patch['slug'] = $slug;
        }
        foreach (['excerpt', 'content', 'author_name'] as $field) if (isset($data[$field]) && is_string($data[$field])) $patch[$field] = $data[$field];
        if ((Arr::str($data, 'image', '') !== '') || (Arr::str($data, 'cover_image', '') !== '')) $patch['image'] = Arr::str($data, 'image', '') !== '' ? Arr::str($data, 'image') : Arr::str($data, 'cover_image');
        if (isset($data['status']) && in_array($data['status'], ['draft', 'published', 'archived'], true)) {
            $patch['status'] = $data['status'];
            if ($data['status'] === 'published' && Arr::str($row->toArray(), 'published_at', '') === '') $patch['published_at'] = date('Y-m-d H:i:s');
        }
        if (isset($data['featured'])) $patch['featured'] = (bool) $data['featured'] ? 1 : 0;
        if (array_key_exists('category_id', $data)) $patch['category_id'] = $data['category_id'] === null ? null : (string) (int) $data['category_id'];
        if ($patch !== []) $row->update($patch);
        $fresh = Post::find((int) $row->getAttribute('id'));
        return $this->success(self::row($fresh === null ? [] : $fresh->toArray()), 'Post updated');
    }

    public function destroy(Request $request): Response
    {
        $row = Post::find((int) Arr::requiredParam($request, 'id'));
        if ($row === null) return $this->error('Post not found', 404);
        $row->delete();
        return $this->noContent();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function row(array $row): array
    {
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null, 'title' => Arr::str($row, 'title'), 'slug' => Arr::str($row, 'slug'),
            'excerpt' => Arr::str($row, 'excerpt', ''), 'content' => Arr::str($row, 'content', ''),
            'image' => Arr::str($row, 'image', '') === '' ? null : Arr::str($row, 'image'),
            'status' => Arr::str($row, 'status', 'draft'), 'featured' => (int) ($row['featured'] ?? 0) === 1,
            'category_id' => isset($row['category_id']) && $row['category_id'] !== null && $row['category_id'] !== '' ? (int) $row['category_id'] : null,
            'tags' => [], 'author_name' => Arr::str($row, 'author_name', ''),
            'published_at' => Arr::str($row, 'published_at', '') === '' ? null : Arr::str($row, 'published_at'),
            'created_at' => Skeleton::date($row['created_at'] ?? null), 'updated_at' => Skeleton::date($row['updated_at'] ?? null),
        ];
    }
}
