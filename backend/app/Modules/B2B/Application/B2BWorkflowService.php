<?php
declare(strict_types=1);
namespace App\Modules\B2B\Application;
use App\Modules\B2B\Contracts\B2BWorkflowStore;
use RuntimeException;
final class B2BWorkflowService {
    public function __construct(private readonly B2BWorkflowStore $store) {}
    /** @return array<string,mixed> */
    public function transition(string $orderId, int $userId, string $to, string $key, ?string $reason = null): array {
        if (trim($key) === '') throw new RuntimeException('Idempotency-Key is required');
        if (!in_array($to, ['draft', 'submit', 'approve', 'reject'], true)) throw new RuntimeException('Invalid B2B order transition');
        $order = $this->store->order($orderId); if ($order === null) throw new RuntimeException('B2B order not found');
        $companyId = isset($order['company_id']) && is_string($order['company_id']) ? $order['company_id'] : ''; if ($companyId === '') throw new RuntimeException('Order is not assigned to a company');
        $member = $this->store->member($companyId, $userId); if ($member === null) throw new RuntimeException('User is not a company member');
        $role = isset($member['role']) && is_string($member['role']) ? $member['role'] : ''; if (!in_array($role, ['buyer','approver','admin'], true)) throw new RuntimeException('User cannot purchase for this company');
        $existing = $this->store->transitionByKey($key); if ($existing !== null) return $existing;
        $workflow = $this->store->workflow($orderId); $from = isset($workflow['state']) && is_string($workflow['state']) ? $workflow['state'] : 'draft';
        $allowed = ['draft'=>['submit'], 'submitted'=>['approve','reject']]; if ($to !== 'draft' && !in_array($to, $allowed[$from] ?? [], true)) throw new RuntimeException('Invalid B2B order transition'); if ($to === 'draft' && $workflow !== null) throw new RuntimeException('Invalid B2B order transition');
        if ($to === 'submit' && !in_array($role, ['buyer','admin'], true)) throw new RuntimeException('User cannot submit for this company');
        if (in_array($to, ['approve','reject'], true)) {
            if (!in_array($role, ['approver','admin'], true)) throw new RuntimeException('User cannot approve for this company');
            $channelId = isset($order['channel_id']) && is_string($order['channel_id']) ? $order['channel_id'] : null;
            $scope = $this->store->scope($companyId, 'order.approve', $channelId) ?? ($channelId === null ? null : $this->store->scope($companyId, 'order.approve', null));
            $active = isset($scope['active']) && is_numeric($scope['active']) ? (int)$scope['active'] : 0; if ($scope === null || $active !== 1) throw new RuntimeException('Approval scope is not granted');
            $total = isset($order['grand_total_minor']) && is_numeric($order['grand_total_minor']) ? (int)$order['grand_total_minor'] : 0; $limit = isset($scope['approval_limit_minor']) && is_numeric($scope['approval_limit_minor']) ? (int)$scope['approval_limit_minor'] : null; if ($to === 'approve' && $limit !== null && $total > $limit) throw new RuntimeException('Order exceeds approval limit');
        }
        $amount = isset($order['grand_total_minor']) && is_numeric($order['grand_total_minor']) ? (int)$order['grand_total_minor'] : 0; $currency = isset($order['currency']) && is_string($order['currency']) ? $order['currency'] : 'VND';
        if ($amount <= 0 || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) throw new RuntimeException('B2B order credit amount or currency is invalid');
        if ($to === 'submit') $this->store->reserveCredit($companyId, $orderId, $amount, $currency, 'reserve:'.$key);
        if ($to === 'reject') $this->store->releaseCredit($orderId, 'release:'.$key);
        $state = ['draft' => 'draft', 'submit' => 'submitted', 'approve' => 'approved', 'reject' => 'rejected'][$to];
        return $this->store->transitionOrder(['order_id'=>$orderId,'company_id'=>$companyId,'from_state'=>$from,'to_state'=>$state,'user_id'=>$userId,'idempotency_key'=>$key,'reason'=>$reason ?? '']);
    }
    /** @return array<string,mixed> */ public function credit(string $companyId, int $userId, bool $platformAdmin = false): array {
        if (!$platformAdmin && $this->store->member($companyId, $userId) === null) throw new RuntimeException('User is not a company member');
        return $this->store->creditEvidence($companyId);
    }
}
