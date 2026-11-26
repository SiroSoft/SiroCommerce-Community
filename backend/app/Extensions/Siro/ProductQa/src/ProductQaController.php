<?php

declare(strict_types=1);

namespace App\Extensions\Siro\ProductQa;

use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/** Thin HTTP layer: validate -> service. Served under /api/ext/siro/product-qa/. */
final class ProductQaController extends Controller
{
    public function __construct(private readonly ProductQaService $service = new ProductQaService())
    {
    }

    public function publicList(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
            $productId = $request->queryString('product_id', '');
            $rows = $this->service->listApproved($scope, $productId);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $data = [];
        foreach ($rows as $row) {
            $data[] = $this->publicRow($row);
        }
        return $this->success($data, 'Product questions');
    }

    public function submit(Request $request): Response
    {
        $data = $request->validate([
            'product_id' => 'required|string|max:64',
            'product_slug' => 'nullable|string|max:160',
            'customer_name' => 'required|string|min:2|max:80',
            'question' => 'required|string|min:5|max:1000',
        ]);
        try {
            $scope = CommerceScope::fromRequest($request);
            $key = $request->header('idempotency-key', '');
            $row = $this->service->submit($scope, $data, is_string($key) && $key !== '' ? $key : null);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 409);
        }
        return $this->success($this->publicRow($row), 'Question submitted for review', 201);
    }

    public function adminList(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
            $state = $request->queryString('state', 'pending');
            $productId = $request->queryString('product_id', '');
            $rows = $this->service->adminList($scope, $state, $productId !== '' ? $productId : null);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
        return $this->success($rows, 'Product questions');
    }

    public function adminUpdate(Request $request): Response
    {
        $data = $request->validate([
            'state' => 'nullable|string|in:approved,rejected,approve,reject',
            'action' => 'nullable|string|in:approve,reject,answer',
            'answer' => 'nullable|string|max:2000',
        ]);
        if (isset($data['action']) && is_string($data['action']) && $data['action'] !== '') {
            $action = strtolower(trim($data['action']));
            if ($action === 'approve') {
                $data['state'] = 'approved';
            } elseif ($action === 'reject') {
                $data['state'] = 'rejected';
            }
        }
        unset($data['action']);
        try {
            $scope = CommerceScope::fromRequest($request);
            $id = $request->param('id', '');
            $row = $this->service->adminUpdate($scope, is_string($id) ? $id : '', $data);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 409);
        }
        if ($row === null) {
            return $this->error('Question not found', 404);
        }
        return $this->success($row, 'Question updated');
    }

    /**
     * Storefront projection: no scope, idempotency key or moderation state is
     * exposed to anonymous shoppers.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicRow(array $row): array
    {
        return [
            'id' => $this->stringValue($row['id'] ?? null),
            'product_id' => $this->stringValue($row['product_id'] ?? null),
            'product_slug' => $this->nullableString($row['product_slug'] ?? null),
            'customer_name' => $this->stringValue($row['customer_name'] ?? null),
            'question' => $this->stringValue($row['question'] ?? null),
            'answer' => $this->nullableString($row['answer'] ?? null),
            'created_at' => $this->nullableString($row['created_at'] ?? null),
            'answered_at' => $this->nullableString($row['answered_at'] ?? null),
        ];
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return is_scalar($value) ? (string) $value : null;
    }
}
