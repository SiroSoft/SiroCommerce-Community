<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Repositories\OrderRepository;
use App\Support\Arr;
use App\Support\Skeleton;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use App\Support\ScopeAuthorizer;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Admin-console order surface (skeleton API dialect): numeric ids,
 * major-unit money, embedded items and customer.
 */
final class SkeletonOrderController extends Controller
{
    public function __construct(private readonly OrderRepository $orders)
    {
    }

    public function index(Request $request): Response
    {
        $page = max($request->queryInt('page', 1), 1);
        $perPage = min(max($request->queryInt('per_page', 20), 1), 100);
        try {
            $scope = CommerceScope::fromRequest($request);
            $user = $request->user();
            ScopeAuthorizer::assertAllowed($scope, is_array($user) ? $user : []);
        } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); }
        $search = trim($request->queryString('search', $request->queryString('q', '')));
        $status = trim($request->queryString('status', ''));
        $customerId = trim($request->queryString('customer_id', ''));
        $filters = [];
        if ($search !== '') $filters['q'] = $search;
        if ($status !== '') $filters['status'] = $status;
        if ($customerId !== '') $filters['customer_id'] = $customerId;
        $result = $this->orders->paginateLatest($page, $perPage, $scope, $filters);
        return $this->paginated(array_map(self::row(...), $result['data']), $result['meta'], 'Orders list');
    }

    public function show(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
            $user = $request->user();
            ScopeAuthorizer::assertAllowed($scope, is_array($user) ? $user : []);
        } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); }
        $row = $this->orders->findInScope((string) Arr::requiredParam($request, 'id'), $scope);
        if ($row === null) return $this->error('Order not found', 404);
        return $this->success(self::row($row, true), 'Order detail');
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function row(array $row, bool $detail = false): array
    {
        $id = Arr::str($row, 'id');
        $lines = OrderLine::query()->where('order_id', $id)->orderBy('id', 'asc')->get();
        $items = array_map(static function ($line): array {
            /** @var array<string, mixed> $lineRow */
            $lineRow = $line->toArray();
            $qty = (int) ($lineRow['qty'] ?? 0);
            $unit = (int) ($lineRow['unit_price_minor'] ?? 0);
            return [
                'name' => Arr::str($lineRow, 'name'), 'product_name' => Arr::str($lineRow, 'name'),
                'quantity' => $qty, 'price' => Skeleton::major($unit), 'unit_price' => Skeleton::major($unit),
                'subtotal' => Skeleton::major($unit * $qty),
            ];
        }, $lines);
        $email = Arr::str($row, 'guest_email', '');
        $payments = [];
        if ($detail) {
            foreach (Payment::query()->where('order_id', $id)->orderBy('id', 'asc')->get() as $payment) {
                /** @var array<string, mixed> $pay */
                $pay = $payment->toArray();
                $payments[] = ['id' => Arr::str($pay, 'id'), 'provider' => Arr::str($pay, 'provider'), 'state' => Arr::str($pay, 'state'), 'amount' => Skeleton::major((int) ($pay['amount_minor'] ?? 0)), 'captured' => Skeleton::major((int) ($pay['captured_amount_minor'] ?? 0)), 'refunded' => Skeleton::major((int) ($pay['refunded_amount_minor'] ?? 0))];
            }
        }
        return [
            'id' => Skeleton::num($id), 'status' => Arr::str($row, 'state', 'pending'),
            'order_number' => Arr::str($row, 'number'),
            'customer' => $email === '' ? null : ['email' => $email],
            'customer_email' => $email, 'payment_status' => Arr::str($row, 'state', 'pending'),
            'total' => Skeleton::major((int) ($row['grand_total_minor'] ?? 0)),
            'items' => $items,
            'created_at' => Skeleton::date($row['created_at'] ?? null), 'updated_at' => Skeleton::date($row['created_at'] ?? null),
        ] + ($detail ? ['subtotal' => Skeleton::major((int) ($row['subtotal_minor'] ?? 0)), 'discount' => Skeleton::major((int) ($row['discount_minor'] ?? 0)), 'shipping' => Skeleton::major((int) ($row['shipping_minor'] ?? 0)), 'tax' => Skeleton::major((int) ($row['tax_minor'] ?? 0)), 'payments' => $payments] : []);
    }

    /** @return array<string, mixed>|null */
    public static function find(string $idOrNum): ?array
    {
        $id = ctype_digit($idOrNum) ? Skeleton::resolveId('orders', (int) $idOrNum) : $idOrNum;
        if ($id === null) return null;
        $row = Order::find($id);
        if ($row === null) return null;
        /** @var array<string, mixed> $out */
        $out = $row->toArray();
        return $out;
    }
}
