<?php

declare(strict_types=1);

namespace App\Extensions\Siro\ProductLabels;

use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/** Thin HTTP layer: validate -> service. Served under /api/ext/siro/product-labels/. */
final class ProductLabelsController extends Controller
{
    public function __construct(private readonly ProductLabelsService $service = new ProductLabelsService())
    {
    }

    public function stats(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        $stats = $this->service->stats($scope);
        return $this->success([
            'stats' => [
                ['label' => 'Total rules', 'value' => $stats['total']],
                ['label' => 'Active rules', 'value' => $stats['active']],
                ['label' => 'New rules', 'value' => $stats['by_kind']['new']],
                ['label' => 'Sale rules', 'value' => $stats['by_kind']['sale']],
                ['label' => 'Best seller rules', 'value' => $stats['by_kind']['best_seller']],
                ['label' => 'Custom rules', 'value' => $stats['by_kind']['custom']],
            ],
            'total' => $stats['total'],
            'active' => $stats['active'],
            'by_kind' => $stats['by_kind'],
        ], 'Product label stats');
    }

    public function index(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        $rules = $this->service->list($scope);
        // The admin table panel reads `data.table`; the rules list itself
        // rides along as `data.rules` for API consumers.
        return $this->success(['rules' => $rules, 'table' => $this->rulesTable($rules)], 'Product label rules');
    }

    public function show(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        $rule = $this->service->find($scope, $this->ruleId($request));
        if ($rule === null) {
            return $this->error('Product label rule not found', 404);
        }
        return $this->success($rule, 'Product label rule');
    }

    public function store(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'rule_key' => 'required|string',
            'text' => 'required|string',
            'kind' => 'required|string',
        ]);
        // Optional fields are read raw: validate() only returns declared keys.
        $body = $request->body();
        foreach (['conditions', 'priority', 'active'] as $field) {
            if (array_key_exists($field, $body)) {
                $data[$field] = $body[$field];
            }
        }
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        try {
            $rule = $this->service->create(
                $scope,
                $data,
                $this->idempotencyKey($request),
                $this->actor($request)
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
        return $this->created($rule, 'Product label rule created');
    }

    public function update(Request $request): Response
    {
        // Partial update: the service validates every supplied field.
        $body = $request->body();
        $data = [];
        foreach (['rule_key', 'text', 'kind', 'conditions', 'priority', 'active'] as $field) {
            if (array_key_exists($field, $body)) {
                $data[$field] = $body[$field];
            }
        }
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        try {
            $rule = $this->service->update($scope, $this->ruleId($request), $data, $this->actor($request));
        } catch (\InvalidArgumentException $exception) {
            $status = $exception->getMessage() === 'Product label rule not found' ? 404 : 422;
            return $this->error($exception->getMessage(), $status);
        }
        return $this->success($rule, 'Product label rule updated');
    }

    public function destroy(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        $deleted = $this->service->delete($scope, $this->ruleId($request), $this->actor($request));
        return $this->success(['deleted' => $deleted], 'Product label rule deleted');
    }

    public function publicLabels(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        $slug = trim($request->queryString('product_slug', ''));
        if ($slug === '') {
            return $this->error('product_slug is required', 422);
        }
        $result = $this->service->labelsForSlug($scope, $slug);
        if ($result === null) {
            // Unknown product: an empty label list, not an error — the slot
            // simply renders nothing.
            return $this->success(['product_id' => null, 'product_slug' => $slug, 'labels' => []], 'Product labels');
        }
        return $this->success($result, 'Product labels');
    }

    private function ruleId(Request $request): string
    {
        $id = $request->param('id');
        return is_string($id) ? $id : '';
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key', '');
        return is_string($key) && $key !== '' ? $key : null;
    }

    private function actor(Request $request): string
    {
        $user = $request->user();
        if (is_array($user) && isset($user['email']) && is_string($user['email']) && trim($user['email']) !== '') {
            return trim($user['email']);
        }
        return 'system';
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @return array{columns: list<array{key: string, label: string}>, rows: list<array<string, string>>}
     */
    private function rulesTable(array $rules): array
    {
        $columns = [
            ['key' => 'rule_key', 'label' => 'Key'],
            ['key' => 'text', 'label' => 'Text'],
            ['key' => 'kind', 'label' => 'Kind'],
            ['key' => 'priority', 'label' => 'Priority'],
            ['key' => 'active', 'label' => 'Active'],
        ];
        $rows = [];
        foreach ($rules as $rule) {
            $row = [];
            foreach ($columns as $column) {
                $value = $rule[$column['key']] ?? '';
                $row[$column['key']] = is_bool($value) ? ($value ? '1' : '0') : (is_scalar($value) ? (string) $value : '');
            }
            $rows[] = $row;
        }
        return ['columns' => $columns, 'rows' => $rows];
    }
}
