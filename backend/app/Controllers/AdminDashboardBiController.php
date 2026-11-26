<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Dashboard\Application\DashboardBiService;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/** Actionable admin business pulse (read-only, scoped, fail-closed to zeros). */
final class AdminDashboardBiController extends Controller
{
    public function __construct(private readonly DashboardBiService $bi) {}

    public function index(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $e) {
            return $this->error($e->getMessage(), 422, [], 'INVALID_SCOPE');
        }
        $range = trim($request->queryString('range', '30d'));
        if ($range === '') $range = '30d';
        try {
            return $this->success($this->bi->bi($range, $scope), 'Business pulse');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, [], 'INVALID_RANGE');
        } catch (\Throwable) {
            return $this->success($this->bi->emptyPayload($range, $scope), 'Business pulse');
        }
    }
}
