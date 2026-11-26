<?php
declare(strict_types=1);

namespace App\Modules\B2B\Application;

use App\Domain\Money;
use App\Support\Ids;
use RuntimeException;
use Siro\Core\Database;

final class B2BPurchaseOrderService
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(string $companyId, int $userId, array $data, string $key): array
    {
        $number = trim($this->string($data['po_number'] ?? null));
        $currency = strtoupper($this->string($data['currency'] ?? 'VND'));
        $total = $this->integer($data['total_minor'] ?? -1);
        $contractId = $this->string($data['contract_id'] ?? null);
        if ($companyId === '' || $key === '' || $number === '' || !$this->validMinor($data['total_minor'] ?? null) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new RuntimeException('Purchase order number, amount, currency, and Idempotency-Key are required');
        }
        $pdo = Database::connection();
        $member = $pdo->prepare('SELECT role FROM company_members WHERE company_id=? AND user_id=? AND status=?');
        $member->execute([$companyId, $userId, 'active']);
        $memberRole = $member->fetchColumn();
        if ($memberRole === false) throw new RuntimeException('User is not a company member');
        if (!in_array((string) $memberRole, ['buyer', 'approver', 'admin'], true)) throw new RuntimeException('User cannot create purchase orders');
        $existing = $pdo->prepare('SELECT * FROM b2b_purchase_orders WHERE idempotency_key=?');
        $existing->execute([$key]); $row = $existing->fetch(\PDO::FETCH_ASSOC);
        if (is_array($row)) {
            if ($row['company_id'] !== $companyId || $row['currency'] !== $currency || $this->integer($row['total_minor'] ?? null) !== $total || $row['po_number'] !== $number) throw new RuntimeException('Idempotency key conflicts with the purchase order');
            return $this->row($row);
        }
        $terms = $pdo->prepare('SELECT currency,credit_limit_minor FROM b2b_company_terms WHERE company_id=? AND state=?');
        $terms->execute([$companyId, 'active']);
        $termsRow = $terms->fetch(\PDO::FETCH_ASSOC);
        if (is_array($termsRow)) {
            if ($this->string($termsRow['currency'] ?? null) !== $currency || $this->integer($termsRow['credit_limit_minor'] ?? null) < $total) throw new RuntimeException('Purchase order exceeds company limit');
        }
        if ($contractId !== '') {
            $contract = $pdo->prepare('SELECT id,currency,status,credit_limit_minor FROM company_contracts WHERE id=? AND company_id=?');
            $contract->execute([$contractId, $companyId]); $contractRow = $contract->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($contractRow) || $contractRow['status'] !== 'active') throw new RuntimeException('B2B contract is not active');
            if ($contractRow['currency'] !== $currency || $this->integer($contractRow['credit_limit_minor'] ?? null) < $total) throw new RuntimeException('Purchase order exceeds contract limit');
        }
        $id = Ids::new();
        $pdo->prepare('INSERT INTO b2b_purchase_orders (id,company_id,contract_id,po_number,currency,total_minor,state,created_by,idempotency_key,evidence_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id,$companyId,$contractId !== '' ? $contractId : null,$number,$currency,$total,'draft',$userId,$key,json_encode(['source'=>'native'], JSON_THROW_ON_ERROR),date('Y-m-d H:i:s'),date('Y-m-d H:i:s')]);
        return ['id'=>$id,'company_id'=>$companyId,'contract_id'=>$contractId !== '' ? $contractId : null,'po_number'=>$number,'currency'=>$currency,'total_minor'=>$total,'state'=>'draft','created_by'=>$userId,'idempotency_key'=>$key];
    }

    /** @return array<string,mixed> */
    public function transition(string $id, int $userId, string $action, string $key, string $reason = ''): array
    {
        if ($key === '' || !in_array($action, ['submit', 'approve', 'reject'], true)) throw new RuntimeException('Invalid purchase order transition');
        $pdo = Database::connection();
        return Database::transaction(function () use ($pdo, $id, $userId, $action, $key, $reason): array {
            // The PO row serializes different idempotency keys for the same action.
            $old = $pdo->prepare('SELECT t.*,p.company_id FROM b2b_purchase_order_transitions t JOIN b2b_purchase_orders p ON p.id=t.purchase_order_id WHERE t.idempotency_key=?'); $old->execute([$key]); $replay = $old->fetch(\PDO::FETCH_ASSOC);
            if (is_array($replay)) {
                $expectedState = ['submit' => 'submitted', 'approve' => 'approved', 'reject' => 'rejected'][$action];
                if (($replay['purchase_order_id'] ?? null) !== $id || ($replay['to_state'] ?? null) !== $expectedState) throw new RuntimeException('Idempotency key conflicts with another purchase order');
                $member = $pdo->prepare('SELECT id FROM company_members WHERE company_id=? AND user_id=? AND status=?');
                $member->execute([$replay['company_id'], $userId, 'active']);
                if ($member->fetchColumn() === false) throw new RuntimeException('User is not a company member');
                return $this->row($replay);
            }
            $stmt = $pdo->prepare('SELECT * FROM b2b_purchase_orders WHERE id=?' . $this->lockSuffix()); $stmt->execute([$id]); $po = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($po)) throw new RuntimeException('B2B purchase order not found');
            $member = $pdo->prepare('SELECT role FROM company_members WHERE company_id=? AND user_id=? AND status=?'); $member->execute([$po['company_id'],$userId,'active']); $role = $member->fetchColumn();
            if ($role === false) throw new RuntimeException('User is not a company member');
            $from = $this->string($po['state']); $to = ['submit'=>'submitted','approve'=>'approved','reject'=>'rejected'][$action];
            if (($from !== 'draft' && $from !== 'submitted') || ($action !== 'submit' && $from !== 'submitted')) throw new RuntimeException('Invalid purchase order transition');
            if ($action !== 'submit' && !in_array((string) $role, ['approver','admin'], true)) throw new RuntimeException('User cannot approve purchase orders');
            if ($action === 'approve') {
                $scope = $pdo->prepare('SELECT approval_limit_minor,active FROM company_approval_scopes WHERE company_id=? AND scope=? AND channel_id IS NULL'); $scope->execute([$po['company_id'],'purchase_order.approve']); $limit = $scope->fetch(\PDO::FETCH_ASSOC);
                if (!is_array($limit) || $this->integer($limit['active'] ?? 0) !== 1) throw new RuntimeException('Purchase order approval scope is not granted');
                if (($limit['approval_limit_minor'] ?? null) !== null && $this->integer($po['total_minor'] ?? null) > $this->integer($limit['approval_limit_minor'])) throw new RuntimeException('Purchase order exceeds approval limit');
            }
            $pdo->prepare('UPDATE b2b_purchase_orders SET state=?,approved_by=?,updated_at=? WHERE id=?')->execute([$to,$to === 'approved' ? $userId : null,date('Y-m-d H:i:s'),$id]);
            $transitionId = Ids::new(); $pdo->prepare('INSERT INTO b2b_purchase_order_transitions (id,purchase_order_id,from_state,to_state,user_id,idempotency_key,reason,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$transitionId,$id,$from,$to,$userId,$key,$reason,date('Y-m-d H:i:s'),date('Y-m-d H:i:s')]);
            return ['id'=>$transitionId,'purchase_order_id'=>$id,'from_state'=>$from,'to_state'=>$to,'user_id'=>$userId,'idempotency_key'=>$key];
        });
    }

    private function string(mixed $value): string { return is_string($value) ? $value : ''; }
    private function integer(mixed $value): int { return is_int($value) ? $value : (is_numeric($value) ? (int) $value : -1); }
    private function validMinor(mixed $value): bool { return is_int($value) && $value >= 0 && $value <= Money::MAX_MINOR; }
    private function lockSuffix(): string { return strtolower((string) Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME)) === 'mysql' ? ' FOR UPDATE' : ''; }
    /** @return array<string,mixed> */ private function row(mixed $value): array { if (!is_array($value)) throw new RuntimeException('Purchase order evidence unavailable'); $out=[]; foreach ($value as $k=>$v) if (is_string($k)) $out[$k]=$v; return $out; }
}
