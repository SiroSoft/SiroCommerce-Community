<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Customer\Application\CustomerCommerceService;
use App\Modules\Finance\Application\FinancialDocumentQueryService;
use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class CustomerFinancialController extends Controller
{
    public function __construct(private readonly CustomerCommerceService $customers, private readonly FinancialDocumentQueryService $documents) {}

    public function orderDocuments(Request $request): Response
    {
        [$customer, $aliasIds] = $this->customerScope($request);
        if ($customer === null) return $this->error('Not found', 404);
        try { $result = $this->documents->forCustomer(Arr::requiredStr($customer, 'id'), Arr::requiredParam($request, 'id'), $this->scope($request), $aliasIds); }
        catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); }
        return $result === null ? $this->error('Not found', 404) : $this->success($result, 'Financial documents');
    }

    public function document(Request $request): Response
    {
        [$customer, $aliasIds] = $this->customerScope($request);
        if ($customer === null) return $this->error('Not found', 404);
        try { $result = $this->documents->documentForCustomer(Arr::requiredStr($customer, 'id'), Arr::requiredParam($request, 'type'), Arr::requiredParam($request, 'id'), $this->scope($request), $aliasIds); }
        catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); }
        return $result === null ? $this->error('Not found', 404) : $this->success($result, 'Financial document');
    }

    private function customer(Request $request): ?array { $user = $request->user(); $email = is_array($user) ? (string) ($user['email'] ?? '') : ''; return $email === '' ? null : $this->customers->customerForEmail($email); }

    /** @return array{0: array<string, mixed>|null, 1: list<string>} */
    private function customerScope(Request $request): array
    {
        $customer = $this->customer($request);
        if ($customer === null) return [null, []];
        $ids = CustomerCommerceService::orderIdentities($customer, $request->user());
        return [$customer, array_values(array_filter($ids, static fn (string $id): bool => $id !== Arr::str($customer, 'id')))];
    }
    private function scope(Request $request): ?CommerceScope
    {
        foreach (['store_id', 'channel_id', 'locale', 'currency'] as $key) {
            $header = match ($key) { 'store_id' => 'X-Store-Id', 'channel_id' => 'X-Channel-Id', 'locale' => 'X-Locale', default => 'X-Currency' };
            if ($request->queryString($key, '') !== '' || $request->header($header, '') !== '') return CommerceScope::fromRequest($request);
        }
        return null;
    }
}
