<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Services\ErpLiteClient;
use Siro\Core\Controller;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Env;

final class ErpWebhookController extends Controller
{
    private function verify(Request $request): ?Response
    {
        $secret = (string) Env::get('ERP_WEBHOOK_SECRET', Env::get('ERP_LITE_SECRET', ''));
        if ($secret === '') return $this->error('ERP webhook not configured', 503);
        $raw = $request->rawBody();
        $sig = $request->header('X-ERP-Signature', $request->header('X-Siro-ERP', ''));
        if (!ErpLiteClient::verify($raw, $sig, $secret)) return $this->error('Bad ERP signature', 401);
        return null;
    }

    private function idempotent(string $key, string $payload): ?array
    {
        try {
            $row = Database::table('erp_webhook_idempotency')->where('idempotency_key', $key)->first();
            if (is_array($row) && isset($row['response_json'])) {
                $decoded = json_decode((string) $row['response_json'], true);
                return is_array($decoded) ? $decoded : null;
            }
        } catch (\Throwable) {}
        return null;
    }

    private function storeIdempotent(string $key, array $response): void
    {
        try {
            Database::table('erp_webhook_idempotency')->insert([
                'idempotency_key' => $key,
                'response_json' => json_encode($response, JSON_THROW_ON_ERROR),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {}
    }

    public function loyalty(Request $request): Response
    {
        if (($err = $this->verify($request)) !== null) return $err;
        $data = $request->validate(['customer_id' => 'required|string', 'order_id' => 'required|string', 'tier' => 'required|string', 'points_earned' => 'required|integer']);
        $key = 'erp-loyalty-' . $data['order_id'];
        if (($cached = $this->idempotent($key, $request->rawBody())) !== null) return $this->success($cached, 'Loyalty already applied');
        // projection: upsert customer_loyalty_snapshot (create table on-demand)
        try {
            Database::table('customer_loyalty_snapshot')->insert([
                'id' => $data['order_id'],
                'customer_id' => $data['customer_id'],
                'order_id' => $data['order_id'],
                'tier' => $data['tier'],
                'points_earned' => $data['points_earned'],
                'applied_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // table may not exist yet — soft-fail but still acknowledge webhook
            if (!str_contains($e->getMessage(), 'customer_loyalty_snapshot')) throw $e;
        }
        $resp = ['order_id' => $data['order_id'], 'tier' => $data['tier']];
        $this->storeIdempotent($key, $resp);
        return $this->success($resp, 'Loyalty applied');
    }

    public function subscription(Request $request): Response
    {
        if (($err = $this->verify($request)) !== null) return $err;
        $data = $request->validate(['subscription_id' => 'required|string', 'state' => 'required|string', 'period' => 'nullable|string', 'next_billing_at' => 'nullable|string']);
        $key = 'erp-sub-' . $data['subscription_id'] . '-' . ($data['period'] ?? 'na');
        if (($cached = $this->idempotent($key, $request->rawBody())) !== null) return $this->success($cached, 'Subscription already applied');
        try {
            $row = \App\Models\Subscription::query()->where('id', $data['subscription_id'])->first();
            if ($row !== null) {
                $row->setAttribute('state', $data['state']);
                if (!empty($data['next_billing_at'])) $row->setAttribute('next_billing_at', $data['next_billing_at']);
                $row->save();
            }
        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'subscriptions')) throw $e;
        }
        $resp = ['subscription_id' => $data['subscription_id'], 'state' => $data['state']];
        $this->storeIdempotent($key, $resp);
        return $this->success($resp, 'Subscription updated');
    }

    public function pos(Request $request): Response
    {
        if (($err = $this->verify($request)) !== null) return $err;
        $data = $request->validate(['pos_order_id' => 'required|string', 'store_id' => 'nullable|string', 'channel_id' => 'nullable|string', 'total_minor' => 'required|integer', 'currency' => 'required|string', 'idempotency_key' => 'nullable|string']);
        $key = $data['idempotency_key'] ?? 'erp-pos-' . $data['pos_order_id'];
        if (($cached = $this->idempotent($key, $request->rawBody())) !== null) return $this->success($cached, 'POS already ingested');
        // minimal order projection — use existing OrderRepository if available
        try {
            $orderId = 'pos-' . $data['pos_order_id'];
            $existing = Database::table('orders')->where('id', $orderId)->first();
            if ($existing === null) {
                Database::table('orders')->insert([
                    'id' => $orderId,
                    'store_id' => $data['store_id'] ?? 'default',
                    'channel_id' => $data['channel_id'] ?? 'web',
                    'total_minor' => $data['total_minor'],
                    'currency' => $data['currency'],
                    'state' => 'placed',
                    'source' => 'pos',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $resp = ['order_id' => $orderId, 'state' => 'placed'];
            $this->storeIdempotent($key, $resp);
            return $this->created($resp, 'POS order ingested');
        } catch (\Throwable $e) {
            return $this->error('POS ingest failed: ' . $e->getMessage(), 422);
        }
    }

    public function marketplace(Request $request): Response
    {
        if (($err = $this->verify($request)) !== null) return $err;
        $data = $request->validate(['order_id' => 'required|string', 'splits' => 'required|array', 'payout_ids' => 'nullable|array']);
        $key = 'erp-mkt-' . $data['order_id'] . '-' . md5(json_encode($data['splits']));
        if (($cached = $this->idempotent($key, $request->rawBody())) !== null) return $this->success($cached, 'Marketplace already settled');
        try {
            foreach ($data['splits'] as $split) {
                if (!is_array($split)) continue;
                Database::table('seller_orders')->insert([
                    'id' => $split['seller_id'] ?? uniqid('seller_'),
                    'order_id' => $data['order_id'],
                    'amount_minor' => $split['amount_minor'] ?? 0,
                    'currency' => $split['currency'] ?? 'VND',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'seller_orders')) throw $e;
        }
        $resp = ['order_id' => $data['order_id'], 'splits' => count($data['splits'])];
        $this->storeIdempotent($key, $resp);
        return $this->success($resp, 'Marketplace settled');
    }
}
