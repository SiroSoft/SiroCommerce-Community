<?php

declare(strict_types=1);

namespace App\Extensions\Siro\StickyAddToCart;

use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Thin HTTP layer: validate -> service. Served under
 * /api/ext/siro/sticky-add-to-cart/.
 *
 * Audit note: Wave 0 ships no audit-log middleware or contract for extension
 * routes (the loader only maps auth/cap/throttle + idempotency; no Audit
 * middleware class exists in the repo), the same as all first-party
 * extensions. Settings mutations are written to the error log with scope so
 * they are traceable until Platform adds an audit contract.
 */
final class StickyAddToCartController extends Controller
{
    public function __construct(private readonly StickyAddToCartService $service = new StickyAddToCartService())
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
        return $this->success($settings + ['table' => $this->table($settings)], 'Sticky Add to Cart settings');
    }

    public function stats(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        $settings = $this->service->settings($scope);
        return $this->success(['stats' => [
            ['label' => 'Bar active', 'value' => $settings['active'] === true ? 'Yes' : 'No'],
            ['label' => 'Button label', 'value' => $this->stringValue($settings['button_label'] ?? null)],
            ['label' => 'Show price', 'value' => $settings['show_price'] === true ? 'Yes' : 'No'],
            ['label' => 'Scope', 'value' => $scope->storeId . ' / ' . $scope->channelId],
        ]], 'Sticky Add to Cart overview');
    }

    public function update(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'button_label' => 'required|string|min:2',
            'show_price' => 'required',
            'active' => 'required',
        ]);
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        try {
            $saved = $this->service->save($scope, $data);
            error_log('[sticky-atc] settings updated scope=' . $scope->storeId . '/' . $scope->channelId . ' active=' . ($saved['active'] === true ? '1' : '0'));
            return $this->success($saved, 'Sticky Add to Cart saved');
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
        return $this->success($this->service->publicSettings($scope), 'Sticky Add to Cart');
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{columns: list<array{key: string, label: string}>, rows: list<array<string, string>>}
     */
    private function table(array $settings): array
    {
        $columns = [
            ['key' => 'active', 'label' => 'Active'],
            ['key' => 'button_label', 'label' => 'Button label'],
            ['key' => 'show_price', 'label' => 'Show price'],
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
