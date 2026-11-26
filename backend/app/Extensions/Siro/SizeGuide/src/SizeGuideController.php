<?php

declare(strict_types=1);

namespace App\Extensions\Siro\SizeGuide;

use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/** Thin HTTP layer: validate -> service. Served under /api/ext/siro/size-guide/. */
final class SizeGuideController extends Controller
{
    public function __construct(private readonly SizeGuideService $service = new SizeGuideService())
    {
    }

    public function adminList(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
            $charts = $this->service->adminList($scope);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        return $this->success($charts, 'Size guide charts');
    }

    public function upsert(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'title' => 'required|string',
            'columns' => 'required|array',
            'rows' => 'required|array',
            'note' => 'nullable|string',
            'active' => 'nullable',
        ]);
        try {
            $scope = CommerceScope::fromRequest($request);
            $category = $request->param('category', '');
            $chart = $this->service->upsert($scope, $category, $data);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 409);
        }
        return $this->success($chart, 'Size guide chart saved');
    }

    /** Public, unauthenticated chart for one active category. */
    public function publicChart(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
            $chart = $this->service->publicChart($scope, $request->query('category'));
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
        return $this->success($chart, 'Size guide chart');
    }
}
