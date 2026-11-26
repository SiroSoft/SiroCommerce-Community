<?php

declare(strict_types=1);

namespace App\Extensions\Siro\ShippingFeeRules;

use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/** Thin HTTP layer: validate -> service. Served under /api/ext/siro/shipping-fee-rules/. */
final class ShippingFeeRuleController extends Controller
{
    public function __construct(private readonly ShippingFeeRuleService $service = new ShippingFeeRuleService())
    {
    }

    public function index(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        return $this->success($this->service->list($scope), 'Shipping fee rules');
    }

    public function store(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => 'required|string|min:2|max:120',
            'method' => 'required|string|in:cod,standard,express',
            'min_subtotal_minor' => 'nullable|integer|min:0',
            'fee_minor' => 'required|integer|min:0',
            'free_over_minor' => 'nullable|integer|min:0',
            'active' => 'nullable',
            'priority' => 'nullable|integer|min:0|max:1000',
        ]);
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        $key = $request->header('idempotency-key', '');
        $idempotencyKey = is_string($key) && $key !== '' ? $key : null;
        try {
            return $this->success($this->service->create($scope, $data, $idempotencyKey), 'Shipping fee rule saved');
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 409);
        }
    }

    /**
     * Embedded admin panel payload (spec EXTENSION_UI_SPEC): overview stats
     * plus the scoped rules table. Mutations stay on POST rules.
     */
    public function overview(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        $rules = $this->service->list($scope);
        $active = 0;
        $cod = 0;
        foreach ($rules as $rule) {
            $activeRaw = $rule['active'] ?? 0;
            if (is_numeric($activeRaw) && (int) $activeRaw === 1) {
                $active++;
            }
            if (($rule['method'] ?? null) === 'cod') {
                $cod++;
            }
        }
        $columns = [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'method', 'label' => 'Method'],
            ['key' => 'min_subtotal_minor', 'label' => 'Min subtotal'],
            ['key' => 'fee_minor', 'label' => 'Fee'],
            ['key' => 'free_over_minor', 'label' => 'Free over'],
            ['key' => 'active', 'label' => 'Active'],
            ['key' => 'priority', 'label' => 'Priority'],
        ];
        $rows = [];
        foreach (array_slice($rules, 0, 20) as $rule) {
            $row = [];
            foreach ($columns as $column) {
                $row[$column['key']] = $this->stringValue($rule[$column['key']] ?? null);
            }
            $rows[] = $row;
        }
        return $this->success([
            'stats' => [
                ['label' => 'Rules', 'value' => (string) count($rules)],
                ['label' => 'Active', 'value' => (string) $active],
                ['label' => 'COD rules', 'value' => (string) $cod],
            ],
            'table' => [
                'columns' => $columns,
                'rows' => $rows,
            ],
        ], 'Shipping fee overview');
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return is_scalar($value) ? (string) $value : '';
    }
}
