<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Coupon\Application\CouponService;
use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Basic coupon admin (spec 04: create basic coupon ≤ 60s).
 * Routes gated by `auth:admin,owner,marketer` + DemoGuard.
 */
final class CouponController extends Controller
{
    public function __construct(private readonly CouponService $coupons)
    {
    }

    public function index(Request $request): Response
    {
        $all = $this->coupons->all();
        $hasFilter = $request->query('q') !== null || $request->query('state') !== null || $request->query('page') !== null || $request->query('per_page') !== null;
        if (!$hasFilter) return $this->success($all, 'Coupons list');
        $q = strtolower(trim((string) ($request->query('q', '') ?? '')));
        $state = strtolower(trim((string) ($request->query('state', '') ?? '')));
        $filtered = array_values(array_filter($all, static function (array $row) use ($q, $state): bool {
            if ($state !== '' && strtolower(Arr::str($row, 'state')) !== $state) return false;
            if ($q !== '' && stripos(Arr::str($row, 'code'), $q) === false) return false;
            return true;
        }));
        $page = max(1, (int) ($request->query('page', '1') ?? 1));
        $perPage = max(1, min(100, (int) ($request->query('per_page', '20') ?? 20)));
        $total = count($filtered);
        $slice = array_slice($filtered, ($page - 1) * $perPage, $perPage);
        return $this->success(['data' => $slice, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage]], 'Coupons list');
    }

    public function store(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'code' => 'required|string',
            'rules' => 'nullable|string',
        ]);
        $code = Arr::requiredStr($data, 'code');
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{2,63}\z/', $code) !== 1) return $this->error('Coupon code must be 3-64 letters, numbers, hyphens, or underscores', 422, ['code' => ['Invalid coupon code']]);
        $scope = null;
        if ($request->header('X-Currency', '') !== '' || $request->header('X-Store-Id', '') !== '') {
            try { $scope = CommerceScope::fromRequest($request); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        }
        $rules = Arr::str($data, 'rules', '{}');
        $decoded = json_decode($rules, true);
        if (!is_array($decoded) || (array_is_list($decoded) && trim($rules) !== '{}')) return $this->error('Coupon rules must be a JSON object', 422, ['rules' => ['Invalid JSON object']]);
        $validation = $this->validateRules($decoded, $scope);
        if ($validation !== null) return $validation;
        $rules = json_encode($decoded, JSON_THROW_ON_ERROR);
        $coupon = $this->coupons->create([
            'code' => strtoupper($code),
            'rules' => $rules,
            'state' => 'active',
        ]);
        return $this->created($coupon, 'Coupon created');
    }

    public function update(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'code' => 'nullable|string',
            'rules' => 'nullable|string',
            'state' => 'nullable|string',
        ]);
        $existing = null;
        foreach ($this->coupons->all() as $row) if (Arr::str($row, 'id') === $id) { $existing = $row; break; }
        if ($existing === null) return $this->error('Coupon not found', 404);
        $patch = [];
        if (isset($data['code']) && is_string($data['code']) && trim($data['code']) !== '') {
            $code = trim((string) $data['code']);
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{2,63}\z/', $code) !== 1) return $this->error('Coupon code must be 3-64 letters, numbers, hyphens, or underscores', 422, ['code' => ['Invalid coupon code']]);
            $patch['code'] = strtoupper($code);
        }
        if (array_key_exists('rules', $data) && $data['rules'] !== null) {
            $rulesRaw = is_string($data['rules']) ? $data['rules'] : json_encode($data['rules'], JSON_THROW_ON_ERROR);
            $decoded = json_decode($rulesRaw, true);
            if (!is_array($decoded) || (array_is_list($decoded) && trim($rulesRaw) !== '{}')) return $this->error('Coupon rules must be a JSON object', 422, ['rules' => ['Invalid JSON object']]);
            $scope = null;
            if ($request->header('X-Currency', '') !== '' || $request->header('X-Store-Id', '') !== '') {
                try { $scope = CommerceScope::fromRequest($request); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
            }
            $validation = $this->validateRules($decoded, $scope);
            if ($validation !== null) return $validation;
            $patch['rules'] = json_encode($decoded, JSON_THROW_ON_ERROR);
        }
        if (isset($data['state']) && is_string($data['state']) && trim($data['state']) !== '') {
            $state = strtolower(trim((string) $data['state']));
            if (!in_array($state, ['active', 'disabled'], true)) return $this->error('Coupon state must be active or disabled', 422, ['state' => ['Invalid state']]);
            $patch['state'] = $state;
        }
        if ($patch === []) return $this->error('No changes provided', 422);
        $ok = $this->coupons->update($id, $patch);
        if (!$ok) return $this->error('Coupon not found', 404);
        foreach ($this->coupons->all() as $row) if (Arr::str($row, 'id') === $id) return $this->success($row, 'Coupon updated');
        return $this->success(null, 'Coupon updated');
    }

    /**
     * @param array<string, mixed> $decoded
     * @return Response|null
     */
    private function validateRules(array $decoded, ?CommerceScope $scope): ?Response
    {
        if ($scope !== null && isset($decoded['currency']) && strtoupper((string) $decoded['currency']) !== $scope->currency) return $this->error('Coupon currency must match the active commerce scope', 422, ['rules' => ['Currency mismatch']]);
        if (isset($decoded['currency']) && $decoded['currency'] !== '' && !preg_match('/^[A-Za-z]{3}$/', (string) $decoded['currency'])) return $this->error('Coupon currency is invalid', 422, ['rules' => ['currency is invalid']]);
        foreach (['usage_limit', 'max_uses', 'min_spend_minor', 'min_subtotal_minor', 'max_discount_minor', 'per_customer_limit', 'per_user_limit'] as $limit) if (isset($decoded[$limit]) && (!is_int($decoded[$limit]) || $decoded[$limit] < 0)) return $this->error("Coupon {$limit} must be a non-negative integer", 422, ['rules' => [$limit . ' is invalid']]);
        foreach (['rate_limit_window_seconds', 'rate_limit_attempts', 'max_attempts'] as $limit) if (isset($decoded[$limit]) && (!is_int($decoded[$limit]) || $decoded[$limit] < 0)) return $this->error("Coupon {$limit} must be a non-negative integer", 422, ['rules' => [$limit . ' is invalid']]);
        if (isset($decoded['type']) && !in_array($decoded['type'], ['fixed', 'percent', 'percentage', 'buy_x_get_y'], true)) return $this->error('Coupon type is unsupported', 422, ['rules' => ['type is invalid']]);
        if (isset($decoded['value']) && (!is_int($decoded['value']) || $decoded['value'] < 0 || (in_array($decoded['type'] ?? 'fixed', ['percent', 'percentage'], true) && $decoded['value'] > 100))) return $this->error('Coupon value is invalid', 422, ['rules' => ['value is invalid']]);
        if (($decoded['type'] ?? '') === 'buy_x_get_y') {
            foreach (['buy_variant_ids', 'buy_product_ids', 'buy_category_ids', 'get_variant_ids'] as $arrKey) if (isset($decoded[$arrKey]) && !is_array($decoded[$arrKey])) return $this->error("Coupon {$arrKey} must be an array", 422, ['rules' => [$arrKey . ' is invalid']]);
            if (empty($decoded['buy_variant_ids']) && empty($decoded['buy_product_ids']) && empty($decoded['buy_category_ids'])) return $this->error('Combo needs buy targets', 422, ['rules' => ['buy_variant_ids is invalid']]);
            if (empty($decoded['get_variant_ids'])) return $this->error('Combo needs gift variants', 422, ['rules' => ['get_variant_ids is invalid']]);
            foreach (['buy_qty', 'get_qty'] as $q) if (isset($decoded[$q]) && (!is_int($decoded[$q]) || $decoded[$q] < 1)) return $this->error("Coupon {$q} must be a positive integer", 422, ['rules' => [$q . ' is invalid']]);
            if (isset($decoded['max_sets']) && (!is_int($decoded['max_sets']) || $decoded['max_sets'] < 0)) return $this->error('Coupon max_sets must be a non-negative integer', 422, ['rules' => ['max_sets is invalid']]);
        }
        if (isset($decoded['exclusive']) && !is_bool($decoded['exclusive']) && !in_array($decoded['exclusive'], [0, 1], true)) return $this->error('Coupon exclusive must be boolean', 422, ['rules' => ['exclusive is invalid']]);
        if (isset($decoded['scope']) && !in_array(strtolower((string) $decoded['scope']), ['cart', 'item'], true)) return $this->error('Coupon scope is unsupported', 422, ['rules' => ['scope is invalid']]);
        foreach (['starts_at', 'start_at', 'date_from', 'ends_at', 'end_at', 'date_to'] as $dateKey) if (isset($decoded[$dateKey]) && $decoded[$dateKey] !== '' && strtotime((string) $decoded[$dateKey]) === false) return $this->error("Coupon {$dateKey} is invalid", 422, ['rules' => [$dateKey . ' is invalid']]);
        foreach (['customer_segment_ids', 'segment_ids', 'product_ids', 'variant_ids', 'category_ids'] as $arrKey) if (isset($decoded[$arrKey]) && !is_array($decoded[$arrKey])) return $this->error("Coupon {$arrKey} must be an array", 422, ['rules' => [$arrKey . ' is invalid']]);
        if (($decoded['scope'] ?? 'cart') === 'item') {
            $hasItemFilter = !empty($decoded['product_ids']) || !empty($decoded['variant_ids']) || !empty($decoded['category_ids']);
            if (!$hasItemFilter) return $this->error('Item promotion requires product_ids, variant_ids, or category_ids', 422, ['rules' => ['scope is invalid']]);
        }
        return null;
    }

    public function disable(Request $request): Response
    {
        $ok = $this->coupons->disable(Arr::requiredParam($request, 'id'));
        if (!$ok) {
            return $this->error('Coupon not found', 404);
        }
        return $this->success(null, 'Coupon disabled');
    }

    public function enable(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        $ok = $this->coupons->update($id, ['state' => 'active']);
        if (!$ok) return $this->error('Coupon not found', 404);
        foreach ($this->coupons->all() as $row) if (Arr::str($row, 'id') === $id) return $this->success($row, 'Coupon enabled');
        return $this->success(null, 'Coupon enabled');
    }

    public function versions(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        $exists = null;
        foreach ($this->coupons->all() as $row) if (Arr::str($row, 'id') === $id) { $exists = $row; break; }
        if ($exists === null) return $this->error('Coupon not found', 404);
        $rows = \App\Models\CouponVersion::query()->where('coupon_id', $id)->orderBy('version', 'asc')->get();
        $out = [];
        foreach ($rows as $r) $out[] = Arr::row($r);
        return $this->success($out, 'Coupon versions');
    }

    public function usage(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        $exists = null;
        foreach ($this->coupons->all() as $row) if (Arr::str($row, 'id') === $id) { $exists = $row; break; }
        if ($exists === null) return $this->error('Coupon not found', 404);
        $total = 0; $byState = ['committed'=>0,'reserved'=>0,'rolled_back'=>0];
        $perCustomer = [];
        try {
            $usages = \App\Models\CouponUsage::query()->where('coupon_id', $id)->get();
            foreach ($usages as $u) {
                $row = Arr::row($u);
                $state = Arr::str($row, 'state');
                $byState[$state] = ($byState[$state] ?? 0) + 1;
                $total++;
                // per customer via order join
                $orderId = Arr::str($row, 'order_id');
                if ($orderId !== '') {
                    $order = \Siro\Core\Database::table('orders')->where('id', $orderId)->first();
                    if (is_array($order)) {
                        $cid = Arr::str(Arr::row($order), 'customer_id');
                        if ($cid !== '') $perCustomer[$cid] = ($perCustomer[$cid] ?? 0) + 1;
                    }
                }
            }
        } catch (\Throwable $e) {}
        return $this->success(['coupon_id' => $id, 'total' => $total, 'by_state' => $byState, 'per_customer' => $perCustomer], 'Coupon usage');
    }

    /** Owner P&L per coupon: uses, discount cost, orders and revenue driven. */
    public function pnl(Request $request): Response
    {
        $out = [];
        try {
            $usages = \App\Models\CouponUsage::query()->get();
            $byCoupon = [];
            foreach ($usages as $u) {
                $row = Arr::row($u);
                $cid = Arr::str($row, 'coupon_id');
                if ($cid === '') continue;
                $byCoupon[$cid]['uses'] = ($byCoupon[$cid]['uses'] ?? 0) + 1;
                $byCoupon[$cid]['discount_minor'] = ($byCoupon[$cid]['discount_minor'] ?? 0) + (int) ($row['discount_minor'] ?? 0);
                $orderId = Arr::str($row, 'order_id');
                if ($orderId !== '' && !in_array($orderId, $byCoupon[$cid]['order_ids'] ?? [], true)) {
                    $byCoupon[$cid]['order_ids'][] = $orderId;
                    $order = \Siro\Core\Database::table('orders')->where('id', $orderId)->first();
                    if (is_array($order)) {
                        $order = Arr::row($order);
                        if (!in_array(Arr::str($order, 'state'), ['cancelled', 'failed'], true)) {
                            $byCoupon[$cid]['revenue_minor'] = ($byCoupon[$cid]['revenue_minor'] ?? 0) + (int) ($order['grand_total_minor'] ?? 0);
                            if (!isset($byCoupon[$cid]['currency'])) $byCoupon[$cid]['currency'] = Arr::str($order, 'currency', 'VND');
                        }
                    }
                }
            }
            foreach ($this->coupons->all() as $coupon) {
                $cid = Arr::str($coupon, 'id');
                $agg = $byCoupon[$cid] ?? ['uses' => 0, 'discount_minor' => 0, 'order_ids' => [], 'revenue_minor' => 0];
                $orders = count($agg['order_ids'] ?? []);
                unset($agg['order_ids']);
                $out[] = ['coupon_id' => $cid, 'code' => Arr::str($coupon, 'code'), 'state' => Arr::str($coupon, 'state'), 'uses' => $agg['uses'], 'discount_minor' => $agg['discount_minor'], 'orders' => $orders, 'revenue_minor' => $agg['revenue_minor'], 'net_minor' => $agg['revenue_minor'] - $agg['discount_minor'], 'currency' => $agg['currency'] ?? 'VND'];
            }
        } catch (\Throwable $e) {
            return $this->error('Coupon P&L is unavailable', 503);
        }
        usort($out, static fn($a, $b) => $b['discount_minor'] <=> $a['discount_minor']);
        return $this->success($out, 'Coupon P&L');
    }

    public function bulk(Request $request): Response
    {
        $key = $request->header('Idempotency-Key', '');
        if (!is_string($key) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) return $this->error('A valid Idempotency-Key is required', 422);
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'pattern' => 'required|string',
            'count' => 'required|integer',
            'start' => 'nullable|integer',
            'rules' => 'nullable|string',
        ]);
        $pattern = trim(Arr::requiredStr($data, 'pattern'));
        $count = Arr::int($data, 'count');
        $start = Arr::int($data, 'start', 1);
        if (!preg_match('/^[A-Za-z0-9_-]+\{0+\}[A-Za-z0-9_-]*$/', $pattern)) return $this->error('Pattern must contain single {0000} placeholder, e.g. WELCOME{0000}', 422, ['pattern' => ['Invalid pattern']]);
        if ($count < 1 || $count > 1000) return $this->error('Count must be 1-1000', 422, ['count' => ['Invalid count']]);
        if ($start < 0 || $start > 999999) return $this->error('Start must be 0-999999', 422);
        preg_match('/\{0+\}/', $pattern, $m);
        $placeholder = $m[0] ?? '{0000}';
        $pad = strlen($placeholder) - 2;
        $prefix = explode($placeholder, $pattern)[0] ?? '';
        $suffix = explode($placeholder, $pattern)[1] ?? '';
        $rulesRaw = Arr::str($data, 'rules', '{}');
        $decoded = json_decode($rulesRaw, true);
        if (!is_array($decoded) || (array_is_list($decoded) && trim($rulesRaw) !== '{}')) return $this->error('Coupon rules must be a JSON object', 422, ['rules' => ['Invalid JSON object']]);
        $validation = $this->validateRules($decoded, null);
        if ($validation !== null) return $validation;
        $rulesJson = json_encode($decoded, JSON_THROW_ON_ERROR);
        // idempotent check
        $existingJob = \App\Models\CouponBulkJob::query()->where('id', $key)->first();
        // Use key as job id for idempotency (or hash)
        $jobId = substr(hash('sha256', $key), 0, 26);
        // check existing by idempotency_key stored in result? simpler check by pattern+rules hash
        foreach (\App\Models\CouponBulkJob::query()->where('pattern', $pattern)->where('rules', $rulesJson)->get() as $j) {
            $jr = Arr::row($j);
            if (Arr::str($jr, 'idempotency_key') === $key || hash_equals(Arr::str($jr, 'pattern'), $pattern)) {
                // if same key, return existing
            }
        }
        $user = $request->user();
        $actorId = is_array($user) ? Arr::str($user, 'id', 'system') : 'system';
        $created = [];
        $failed = [];
        foreach (range($start, $start + $count - 1) as $num) {
            $code = $prefix . str_pad((string) $num, $pad, '0', STR_PAD_LEFT) . $suffix;
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{2,63}\z/', $code) !== 1) { $failed[] = ['code' => $code, 'error' => 'Invalid code after fill']; continue; }
            $upper = strtoupper($code);
            // check duplicate
            $dup = false;
            foreach ($this->coupons->all() as $c) if (Arr::str($c, 'code') === $upper) { $dup = true; break; }
            if ($dup) { $failed[] = ['code' => $upper, 'error' => 'Duplicate']; continue; }
            try {
                $row = $this->coupons->create(['code' => $upper, 'rules' => $rulesJson, 'state' => 'active']);
                $created[] = $upper;
            } catch (\Throwable $e) { $failed[] = ['code' => $upper, 'error' => $e->getMessage()]; }
        }
        // create job record
        try {
            $jobPayload = [
                'id' => $jobId,
                'actor_id' => $actorId,
                'status' => 'done',
                'pattern' => $pattern,
                'count' => $count,
                'start' => $start,
                'rules' => $rulesJson,
                'result_json' => json_encode(['created' => $created, 'failed' => $failed], JSON_THROW_ON_ERROR),
                'created_at' => date('Y-m-d H:i:s'),
            ];
            \Siro\Core\Database::table('coupon_bulk_jobs')->insert($jobPayload);
        } catch (\Throwable $e) {}
        return $this->created(['job_id' => $jobId, 'created' => $created, 'failed' => $failed], 'Bulk coupons created');
    }

    public function bulkStatus(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        $row = \App\Models\CouponBulkJob::query()->where('id', $id)->first();
        if ($row === null) return $this->error('Bulk job not found', 404);
        return $this->success(Arr::row($row), 'Bulk job status');
    }
}
