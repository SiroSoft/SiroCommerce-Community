<?php

declare(strict_types=1);

namespace App\Extensions\Siro\StoreLocator;

use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/** Thin HTTP layer: validate -> service. Served under /api/ext/siro/store-locator/. */
final class StoreLocatorController extends Controller
{
    public function __construct(private readonly StoreLocatorService $service = new StoreLocatorService())
    {
    }

    /** Public, unauthenticated storefront list (active locations only). */
    public function publicList(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }

        $rows = $this->service->listActive($scope);
        $data = [];
        foreach ($rows as $row) {
            $data[] = $this->publicRow($row);
        }
        return $this->success($data, 'Store locations');
    }

    public function adminList(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        return $this->success($this->service->listAll($scope), 'Store locations');
    }

    public function adminCreate(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => 'required|string|min:2|max:120',
            'address' => 'required|string|min:5|max:300',
            'city' => 'required|string|min:1|max:80',
            'phone' => 'nullable|string|max:40',
            'hours' => 'nullable|string|max:120',
            'latitude' => 'nullable|numeric|min:-90|max:90',
            'longitude' => 'nullable|numeric|min:-180|max:180',
            'active' => 'nullable',
        ]);
        try {
            $scope = CommerceScope::fromRequest($request);
            $key = $request->header('idempotency-key', '');
            $row = $this->service->create($scope, $data, is_string($key) && $key !== '' ? $key : null);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 409);
        }
        return $this->success($row, 'Store location created');
    }

    public function adminUpdate(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => 'nullable|string|min:2|max:120',
            'address' => 'nullable|string|min:5|max:300',
            'city' => 'nullable|string|min:1|max:80',
            'phone' => 'nullable|string|max:40',
            'hours' => 'nullable|string|max:120',
            'latitude' => 'nullable|numeric|min:-90|max:90',
            'longitude' => 'nullable|numeric|min:-180|max:180',
            'active' => 'nullable',
        ]);
        try {
            $scope = CommerceScope::fromRequest($request);
            $id = $request->param('id', '');
            $row = $this->service->update($scope, is_string($id) ? $id : '', $data);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 409);
        }
        if ($row === null) {
            return $this->error('Store location not found', 404);
        }
        return $this->success($row, 'Store location updated');
    }

    public function adminDelete(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
            $id = $request->param('id', '');
            $deleted = $this->service->delete($scope, is_string($id) ? $id : '');
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 409);
        }
        return $this->success(['id' => is_string($id) ? $id : '', 'deleted' => $deleted], 'Store location deleted');
    }

    /**
     * Storefront projection: scope columns are not exposed to anonymous
     * shoppers, and no inactive row is ever returned.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicRow(array $row): array
    {
        return [
            'id' => $this->stringValue($row['id'] ?? null),
            'name' => $this->stringValue($row['name'] ?? null),
            'address' => $this->stringValue($row['address'] ?? null),
            'city' => $this->stringValue($row['city'] ?? null),
            'phone' => $this->nullableString($row['phone'] ?? null),
            'hours' => $this->nullableString($row['hours'] ?? null),
            'latitude' => $this->coordinateValue($row['latitude'] ?? null),
            'longitude' => $this->coordinateValue($row['longitude'] ?? null),
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
        $text = is_scalar($value) ? (string) $value : '';
        return $text !== '' ? $text : null;
    }

    private function coordinateValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }
        return null;
    }
}
