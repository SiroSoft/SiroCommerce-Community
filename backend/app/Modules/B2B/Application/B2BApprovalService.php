<?php

declare(strict_types=1);

namespace App\Modules\B2B\Application;

use App\Modules\B2B\Contracts\B2BCommercialStore;
use App\Modules\B2B\Contracts\B2BStore;
use RuntimeException;

final class B2BApprovalService
{
    public function __construct(private readonly B2BStore $store) {}

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createCompany(array $data): array { return $this->store->createCompany($data); }
    /** @return array<string, mixed> */
    public function addMember(string $companyId, int $userId, string $role): array { if (!in_array($role, ['buyer', 'approver', 'admin'], true)) throw new RuntimeException('Invalid company member role'); return $this->store->addMember(['company_id' => $companyId, 'user_id' => $userId, 'role' => $role, 'status' => 'active']); }
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addScope(string $companyId, array $data): array { $data['company_id'] = $companyId; return $this->store->addScope($data); }

    /** @return array<string, mixed> */
    public function approve(string $orderId, int $userId, string $key): array
    {
        if (trim($key) === '') throw new RuntimeException('Idempotency-Key is required');
        $order = $this->store->order($orderId); if ($order === null) throw new RuntimeException('B2B order not found');
        $companyId = $order['company_id'] ?? null; $channelId = isset($order['channel_id']) && is_string($order['channel_id']) ? $order['channel_id'] : null;
        if (!is_string($companyId)) throw new RuntimeException('Order is not assigned to a company');
        $member = $this->store->member($companyId, $userId); if ($member === null || !in_array($member['role'] ?? '', ['approver', 'admin'], true)) throw new RuntimeException('User cannot approve for this company');
        $scope = $this->store->scope($companyId, 'order.approve', $channelId) ?? $this->store->scope($companyId, 'order.approve', null);
        $active = $scope === null ? 0 : (is_int($scope['active'] ?? null) ? $scope['active'] : (is_string($scope['active'] ?? null) && is_numeric($scope['active']) ? (int) $scope['active'] : 0));
        if ($scope === null || $active !== 1) throw new RuntimeException('Approval scope is not granted');
        $limit = is_numeric($scope['approval_limit_minor'] ?? null) ? (int) $scope['approval_limit_minor'] : null; $total = is_numeric($order['grand_total_minor'] ?? null) ? (int) $order['grand_total_minor'] : 0;
        if ($limit !== null && $total > $limit) throw new RuntimeException('Order exceeds approval limit');
        $existing = $this->store->approvalByKey($key); if ($existing !== null) return $existing;
        return $this->store->approval(['company_id' => $companyId, 'order_id' => $orderId, 'user_id' => $userId, 'scope' => 'order.approve', 'status' => 'approved', 'idempotency_key' => $key]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createCreditAccount(string $companyId, array $data): array
    {
        $store = $this->commercialStore(); $limit = $data['limit_minor'] ?? 0;
        $limitMinor = is_int($limit) ? $limit : (is_string($limit) && is_numeric($limit) ? (int) $limit : null);
        if ($limitMinor === null) throw new RuntimeException('Credit limit must be an integer');
        if ($limitMinor < 0) throw new RuntimeException('Credit limit cannot be negative');
        $currency = $data['currency'] ?? 'VND';
        if (!is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) throw new RuntimeException('Credit currency is invalid');
        $data['currency'] = $currency;
        $terms = $data['payment_terms_days'] ?? 0;
        if (!is_int($terms) && !(is_string($terms) && is_numeric($terms))) throw new RuntimeException('Payment terms must be an integer');
        if ((int)$terms < 0) throw new RuntimeException('Payment terms cannot be negative');
        $data['payment_terms_days'] = (int)$terms;
        $data['company_id'] = $companyId; $data['limit_minor'] = $limitMinor; $data['status'] = 'active';
        return $store->creditAccount($data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createContract(string $companyId, array $data): array
    {
        $store = $this->commercialStore(); $number = $data['contract_number'] ?? null;
        if (!is_string($number) || trim($number) === '') throw new RuntimeException('Contract number is required');
        $data['company_id'] = $companyId; $data['status'] = 'pending';
        return $store->contract($data);
    }

    /** @return array<string, mixed> */
    public function approveContract(string $contractId, int $userId, string $key): array
    {
        if (trim($key) === '') throw new RuntimeException('Idempotency-Key is required');
        $store = $this->commercialStore();
        $contract = $store->contractById($contractId); if ($contract === null) throw new RuntimeException('B2B contract not found');
        $companyId = $contract['company_id'] ?? null;
        if (!is_string($companyId) || $companyId === '') throw new RuntimeException('Contract is not assigned to a company');
        if (($contract['status'] ?? 'pending') !== 'pending') throw new RuntimeException('Contract is not pending approval');
        $member = $store->member($companyId, $userId); if ($member === null || !in_array($member['role'] ?? '', ['approver', 'admin'], true)) throw new RuntimeException('User cannot approve for this company');
        $scope = $store->scope($companyId, 'contract.approve', null); $active = $scope === null ? 0 : (is_numeric($scope['active'] ?? null) ? (int) $scope['active'] : 0); if ($scope === null || $active !== 1) throw new RuntimeException('Contract approval scope is not granted');
        $existing = $store->contractApprovalByKey($key); if ($existing !== null) return $existing;
        $approval = $store->contractApproval(['contract_id' => $contractId, 'company_id' => $companyId, 'user_id' => $userId, 'status' => 'approved', 'idempotency_key' => $key]);
        $store->activateContract($contractId, ['status' => 'active']); return $approval;
    }

    private function commercialStore(): B2BCommercialStore
    {
        if (!$this->store instanceof B2BCommercialStore) throw new RuntimeException('B2B credit and contract store unavailable');
        return $this->store;
    }
}
