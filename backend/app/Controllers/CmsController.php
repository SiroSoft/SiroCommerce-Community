<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Cms\Application\CmsService;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;
use App\Modules\Cms\Domain\CmsAccessDenied;
use App\Modules\Cms\Domain\CmsConflict;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;

final class CmsController extends Controller
{
    public function __construct(private readonly CmsService $cms) {}

    public function show(Request $request): Response
    {
        try { $scope = CommerceScope::fromRequest($request); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        $page = $this->cms->published(Arr::requiredParam($request, 'slug'), $scope->locale, $scope);
        if ($page === null) return $this->error('Page not found', 404);
        $etag = '"' . hash('sha256', json_encode($page, JSON_THROW_ON_ERROR)) . '"';
        if ((string) $request->header('If-None-Match', '') === $etag) {
            return Response::json([], 304)->header('Cache-Control', 'public, max-age=60')->header('ETag', $etag)->header('X-Commerce-Scope', $scope->key());
        }
        return $this->success($page, 'Page')->header('Cache-Control', 'public, max-age=60')->header('ETag', $etag)->header('X-Commerce-Scope', $scope->key());
    }

    public function index(Request $request): Response { try { $scope = CommerceScope::fromRequest($request); return $this->success($this->cms->list($request->user(), $scope), 'Pages'); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); } catch (CmsAccessDenied) { return $this->error('Forbidden', 403); } }

    public function store(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['slug' => 'required|string', 'title' => 'required|string', 'body' => 'nullable|string', 'locale' => 'nullable|string', 'status' => 'nullable|string']);
        if (isset($data['status']) && !in_array($data['status'], ['draft', 'published', 'scheduled', 'archived'], true)) return $this->error('Invalid page status', 422);
        try { $scope = CommerceScope::fromRequest($request); return $this->created($this->cms->create($scope->metadata() + $data, $request->user()), 'Page created'); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); } catch (CmsAccessDenied) { return $this->error('Forbidden', 403); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function update(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['slug' => 'nullable|string', 'title' => 'nullable|string', 'body' => 'nullable|string', 'locale' => 'nullable|string', 'status' => 'nullable|string']);
        if (isset($data['status']) && !in_array($data['status'], ['draft', 'published', 'scheduled', 'archived'], true)) return $this->error('Invalid page status', 422);
        try { $scope = CommerceScope::fromRequest($request); $page = $this->cms->update(Arr::requiredParam($request, 'id'), $scope->metadata() + $data + (isset($data['status']) && $data['status'] === 'published' ? ['published_at' => date('Y-m-d H:i:s')] : []), $request->user(), $scope); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); } catch (CmsAccessDenied) { return $this->error('Forbidden', 403); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
        return $page === null ? $this->error('Page not found', 404) : $this->success($page, 'Page updated');
    }

    public function block(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['type' => 'required|string', 'content' => 'required|string', 'sort' => 'nullable|integer', 'status' => 'nullable|string']);
        if (isset($data['status']) && !in_array($data['status'], ['draft', 'published'], true)) return $this->error('Invalid block status', 422);
        try { $scope = CommerceScope::fromRequest($request); $block = $this->cms->addBlock(Arr::requiredParam($request, 'id'), $data, $request->user(), $scope); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); } catch (CmsAccessDenied) { return $this->error('Forbidden', 403); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
        return $block === null ? $this->error('Page not found', 404) : $this->created($block, 'Block created');
    }

    public function updateBlock(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['type' => 'nullable|string', 'content' => 'nullable|string', 'sort' => 'nullable|integer', 'status' => 'nullable|string']);
        if (isset($data['status']) && !in_array($data['status'], ['draft', 'published'], true)) return $this->error('Invalid block status', 422);
        try { $scope = CommerceScope::fromRequest($request); $block = $this->cms->updateBlock(Arr::requiredParam($request, 'id'), $data, $request->user(), $scope); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); } catch (CmsAccessDenied) { return $this->error('Forbidden', 403); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
        return $block === null ? $this->error('Block not found', 404) : $this->success($block, 'Block updated');
    }

    public function menu(Request $request): Response
    {
        try { $scope = CommerceScope::fromRequest($request); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        $menu = $this->cms->menu(Arr::requiredParam($request, 'key'), $scope->locale);
        return $menu === null ? $this->error('Menu not found', 404) : $this->success($menu, 'Menu');
    }

    public function preview(Request $request): Response
    {
        try { $scope = CommerceScope::fromRequest($request); $page = $this->cms->preview(Arr::requiredParam($request, 'id'), $request->user(), $scope); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); } catch (CmsAccessDenied) { return $this->error('Forbidden', 403); }
        return $page === null ? $this->error('Page not found', 404) : $this->success($page, 'Page preview')->header('Cache-Control', 'private, no-store');
    }

    public function revisions(Request $request): Response
    {
        try { $scope = CommerceScope::fromRequest($request); return $this->success($this->cms->revisions(Arr::requiredParam($request, 'type'), Arr::requiredParam($request, 'id'), $scope, $request->user()), 'CMS revisions'); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); } catch (CmsAccessDenied) { return $this->error('Forbidden', 403); }
    }

    public function diff(Request $request): Response
    {
        try { $scope = CommerceScope::fromRequest($request); return $this->success($this->cms->diff(Arr::requiredParam($request, 'type'), Arr::requiredParam($request, 'id'), Arr::requiredParam($request, 'from'), Arr::requiredParam($request, 'to'), $scope, $request->user()), 'CMS revision diff'); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); } catch (CmsAccessDenied) { return $this->error('Forbidden', 403); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function autosave(Request $request): Response
    {
        $data = $request->validate(['title' => 'nullable|string', 'body' => 'nullable|string', 'scheduled_at' => 'nullable|string', 'status' => 'nullable|string', 'media_json' => 'nullable', 'expected_version' => 'nullable|integer']);
        $expectedVersion = isset($data['expected_version']) && is_numeric($data['expected_version']) ? (int) $data['expected_version'] : null;
        try { $scope = CommerceScope::fromRequest($request); $result = $this->cms->autosave(Arr::requiredParam($request, 'id'), $data, $scope, (string) $request->header('Idempotency-Key', ''), $expectedVersion, $request->user()); return $result === null ? $this->error('Page not found', 404) : $this->success($result, 'Page autosaved'); } catch (CmsConflict $e) { return $this->error($e->getMessage(), 409, $e->context); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); } catch (CmsAccessDenied) { return $this->error('Forbidden', 403); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function merge(Request $request): Response
    {
        $data = $request->validate(['title' => 'nullable|string', 'body' => 'nullable|string', 'status' => 'nullable|string', 'scheduled_at' => 'nullable|string', 'media_json' => 'nullable', 'expected_version' => 'nullable|integer']);
        $expectedVersion = isset($data['expected_version']) && is_numeric($data['expected_version']) ? (int) $data['expected_version'] : null;
        try { $scope = CommerceScope::fromRequest($request); $result = $this->cms->merge(Arr::requiredParam($request, 'id'), Arr::requiredParam($request, 'revision'), $data, $scope, (string) $request->header('Idempotency-Key', ''), $expectedVersion, $request->user()); return $result === null ? $this->error('Page not found', 404) : $this->success($result, 'CMS revision merged'); } catch (CmsConflict $e) { return $this->error($e->getMessage(), 409, $e->context); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); } catch (CmsAccessDenied) { return $this->error('Forbidden', 403); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function publish(Request $request): Response
    {
        try { $scope = CommerceScope::fromRequest($request); $page = $this->cms->publish(Arr::requiredParam($request, 'id'), $scope, (string) $request->header('Idempotency-Key', ''), $request->user()); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); } catch (CmsAccessDenied) { return $this->error('Forbidden', 403); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
        return $page === null ? $this->error('Page not found', 404) : $this->success($page, 'Page published');
    }

    public function restore(Request $request): Response
    {
        try { $scope = CommerceScope::fromRequest($request); $page = $this->cms->restore(Arr::requiredParam($request, 'id'), Arr::requiredParam($request, 'revision'), $scope, (string) $request->header('Idempotency-Key', ''), $request->user()); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); } catch (CmsAccessDenied) { return $this->error('Forbidden', 403); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
        return $page === null ? $this->error('Page not found', 404) : $this->success($page, 'CMS revision restored');
    }
}
