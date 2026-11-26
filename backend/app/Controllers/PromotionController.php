<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Promotion\Application\PromotionService;
use App\Models\PromotionDecisionAudit;
use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class PromotionController extends Controller
{
    public function __construct(private readonly PromotionService $promo)
    {
    }

    public function preview(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'code' => 'required|string',
            'subtotal_minor' => 'required|integer',
            'currency' => 'nullable|string',
            'lines' => 'nullable|array',
            'customer_group_id' => 'nullable|string',
            'applied_promotions' => 'nullable|array',
        ]);
        $lines = [];
        if (is_array($data['lines'] ?? null)) {
            foreach ($data['lines'] as $line) {
                if (!is_array($line)) continue;
                $normalized = [];
                foreach ($line as $key => $value) if (is_string($key)) $normalized[$key] = $value;
                $lines[] = $normalized;
            }
        }
        $context = ['customer_group_id' => Arr::str($data, 'customer_group_id')];
        if (isset($data['applied_promotions']) && is_array($data['applied_promotions'])) $context['applied_promotions'] = $data['applied_promotions'];
        $scope = null;
        if ($request->header('X-Currency', '') !== '' || $request->header('X-Store-Id', '') !== '') {
            try { $scope = CommerceScope::fromRequest($request); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        }
        $currency = strtoupper(Arr::str($data, 'currency', $scope->currency ?? 'VND'));
        if ($scope !== null && $currency !== $scope->currency) return $this->error('Promotion currency must match the active commerce scope', 422, ['currency' => ['Currency mismatch']]);
        $result = $this->promo->preview(Arr::requiredStr($data, 'code'), Arr::requiredInt($data, 'subtotal_minor'), $currency, $lines, $context);
        if (isset($result['error'])) {
            $message = is_string($result['error']) ? $result['error'] : 'Promotion is not valid';
            return $this->error($message, 422, ['code' => [$message]]);
        }
        return $this->success($result, 'Coupon valid');
    }

    /** Audit rows contain hashes and allowlisted context only; raw coupon/actor data is never returned. */
    public function audit(Request $request): Response
    {
        $rows = [];
        foreach (PromotionDecisionAudit::query()->orderBy('created_at', 'desc')->limit(100)->get() as $row) $rows[] = $row->toArray();
        return $this->success($rows, 'Promotion decision audit');
    }
}
