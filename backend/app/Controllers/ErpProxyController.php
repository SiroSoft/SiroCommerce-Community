<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Services\ErpLiteClient;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class ErpProxyController extends Controller
{
    public function loyaltyEvaluate(Request $request): Response
    {
        $data = $request->validate(['customer_id' => 'required|string', 'order_id' => 'required|string', 'order_total_minor' => 'required|integer', 'currency' => 'nullable|string']);
        $client = new ErpLiteClient();
        $res = $client->loyaltyEvaluate([
            'customer_id' => $data['customer_id'],
            'order_id' => $data['order_id'],
            'order_total_minor' => $data['order_total_minor'],
            'currency' => $data['currency'] ?? 'VND',
            'store_id' => $request->header('X-Store-Id', 'default'),
            'channel_id' => $request->header('X-Channel-Id', 'web'),
        ], $request->header('Idempotency-Key', 'erp-loyalty-' . $data['order_id']));
        return $this->success($res, 'ERP loyalty evaluated');
    }
    public function subscriptionRenew(Request $request): Response
    {
        $id = \App\Support\Arr::requiredParam($request, 'id');
        $data = $request->validate(['period' => 'nullable|string', 'amount_minor' => 'nullable|integer', 'currency' => 'nullable|string']);
        $client = new ErpLiteClient();
        $res = $client->subscriptionRenew($id, $data, $request->header('Idempotency-Key', 'sub-' . $id . '-' . ($data['period'] ?? date('Y-m'))));
        return $this->success($res, 'ERP subscription renew');
    }
}
