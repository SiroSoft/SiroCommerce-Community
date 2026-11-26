<?php

declare(strict_types=1);

namespace App\Extensions\Siro\FreeShippingBanner;

use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/** Thin HTTP layer: validate -> service. Served under /api/ext/siro/free-shipping-banner/. */
final class FreeShippingBannerController extends Controller
{
    public function __construct(private readonly FreeShippingBannerService $service = new FreeShippingBannerService())
    {
    }

    public function settings(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        $settings = $this->service->settings($scope);
        return $this->success($settings + ['table' => $this->table($settings)], 'Free shipping banner settings');
    }

    public function update(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'threshold_minor' => 'required|integer|min:0',
            'message' => 'required|string|min:2',
            'currency' => 'required|string|regex:/^[A-Za-z]{3}$/',
            'active' => 'required',
        ]);
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        try {
            return $this->success($this->service->save($scope, $data), 'Free shipping banner saved');
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
    }

    public function publicSettings(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        return $this->success($this->service->publicSettings($scope), 'Free shipping banner');
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{columns: list<array{key: string, label: string}>, rows: list<array<string, string>>}
     */
    private function table(array $settings): array
    {
        $columns = [
            ['key' => 'active', 'label' => 'Active'],
            ['key' => 'message', 'label' => 'Message'],
            ['key' => 'threshold_minor', 'label' => 'Threshold (minor)'],
            ['key' => 'currency', 'label' => 'Currency'],
        ];
        $row = [];
        foreach ($columns as $column) {
            $row[$column['key']] = $this->stringValue($settings[$column['key']] ?? null);
        }
        return ['columns' => $columns, 'rows' => [$row]];
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return is_scalar($value) ? (string) $value : '';
    }
}
