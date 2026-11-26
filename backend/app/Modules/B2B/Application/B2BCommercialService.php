<?php
declare(strict_types=1);

namespace App\Modules\B2B\Application;

use App\Support\Ids;
use RuntimeException;
use Siro\Core\Database;
use App\Modules\Shared\Domain\Money;

final class B2BCommercialService
{
    /** @param array<string,mixed> $data */
    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */ public function createGroup(string $companyId, array $data): array
    {
        $code = trim($this->string($data['code'] ?? null)); $name = trim($this->string($data['name'] ?? null));
        if ($code === '' || $name === '') throw new RuntimeException('Group code and name are required');
        $id = Ids::new(); Database::connection()->prepare('INSERT INTO b2b_company_groups (id,company_id,code,name,state) VALUES (?,?,?,?,?)')->execute([$id,$companyId,$code,$name,'active']);
        return ['id'=>$id,'company_id'=>$companyId,'code'=>$code,'name'=>$name,'state'=>'active'];
    }

    /** @param array<string,mixed> $data */
    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */ public function setRolePolicy(string $companyId, string $role, array $data): array
    {
        if (!in_array($role, ['buyer','approver','admin'], true)) throw new RuntimeException('Invalid company role');
        $policy = $data['policy'] ?? []; if (!is_array($policy)) throw new RuntimeException('Role policy must be an object');
         $limit = $data['approval_limit_minor'] ?? null; if ($limit !== null && (!is_int($limit) || $limit < 0 || $limit > Money::MAX_MINOR)) throw new RuntimeException('Approval limit is invalid');
        $pdo = Database::connection(); $id = Ids::new(); $payload = json_encode($policy, JSON_THROW_ON_ERROR); $amount = $limit === null ? null : (int)$limit;
        $existing = $pdo->prepare('SELECT id FROM b2b_company_role_policies WHERE company_id=? AND role=?'); $existing->execute([$companyId, $role]); $existingId = $existing->fetchColumn();
        if ($existingId !== false) {
            $pdo->prepare('UPDATE b2b_company_role_policies SET policy_json=?,approval_limit_minor=?,state=? WHERE id=?')->execute([$payload, $amount, 'active', $existingId]);
        } else {
            $pdo->prepare('INSERT INTO b2b_company_role_policies (id,company_id,role,policy_json,approval_limit_minor,state) VALUES (?,?,?,?,?,?)')->execute([$id,$companyId,$role,$payload,$amount,'active']);
        }
        $row = $pdo->prepare('SELECT * FROM b2b_company_role_policies WHERE company_id=? AND role=?'); $row->execute([$companyId,$role]); return $this->row($row->fetch(\PDO::FETCH_ASSOC));
    }

    /** @param array<string,mixed> $data */
    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */ public function createPriceList(string $companyId, array $data): array
    {
        $code=trim($this->string($data['code']??null)); $currency=strtoupper($this->string($data['currency']??'VND'));
        if ($code==='' || preg_match('/^[A-Z]{3}$/D',$currency)!==1) throw new RuntimeException('Price list code and currency are required');
        $id=Ids::new(); Database::connection()->prepare('INSERT INTO b2b_customer_price_lists (id,company_id,code,currency,state) VALUES (?,?,?,?,?)')->execute([$id,$companyId,$code,$currency,'active']); return ['id'=>$id,'company_id'=>$companyId,'code'=>$code,'currency'=>$currency,'state'=>'active'];
    }

    /** @param array<string,mixed> $data */
    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */ public function addPriceTier(string $priceListId, array $data): array
    {
        $variant=trim($this->string($data['variant_id']??null)); $minimum=$this->integer($data['minimum_qty']??0); $price=$this->integer($data['unit_price_minor']??-1);
         if ($variant==='' || $minimum<1 || $price<0 || $price > Money::MAX_MINOR) throw new RuntimeException('Price tier is invalid');
        $check=Database::connection()->prepare('SELECT id FROM b2b_customer_price_lists WHERE id=? AND state=?'); $check->execute([$priceListId,'active']); if ($check->fetchColumn()===false) throw new RuntimeException('Price list not found');
        $id=Ids::new(); Database::connection()->prepare('INSERT INTO b2b_customer_price_tiers (id,price_list_id,variant_id,minimum_qty,unit_price_minor) VALUES (?,?,?,?,?)')->execute([$id,$priceListId,$variant,$minimum,$price]); return ['id'=>$id,'price_list_id'=>$priceListId,'variant_id'=>$variant,'minimum_qty'=>$minimum,'unit_price_minor'=>$price];
    }

    /** @param array<string,mixed> $data */
    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */ public function setTerms(string $companyId, array $data): array
    {
        $currency=strtoupper($this->string($data['currency']??'VND')); $values=[$companyId,$this->integer($data['minimum_order_minor']??0),$this->integer($data['minimum_order_qty']??0),$this->integer($data['credit_limit_minor']??0),$this->integer($data['payment_terms_days']??0),$currency,'active'];
         if (preg_match('/^[A-Z]{3}$/D',$currency)!==1 || min($values[1],$values[2],$values[3],$values[4])<0 || max($values[1],$values[3]) > Money::MAX_MINOR) throw new RuntimeException('Commercial terms are invalid');
        $pdo = Database::connection(); $existing = $pdo->prepare('SELECT company_id FROM b2b_company_terms WHERE company_id=?'); $existing->execute([$companyId]);
        if ($existing->fetchColumn() !== false) {
            $pdo->prepare('UPDATE b2b_company_terms SET minimum_order_minor=?,minimum_order_qty=?,credit_limit_minor=?,payment_terms_days=?,currency=?,state=? WHERE company_id=?')->execute([$values[1],$values[2],$values[3],$values[4],$currency,'active',$companyId]);
        } else {
            $pdo->prepare('INSERT INTO b2b_company_terms (company_id,minimum_order_minor,minimum_order_qty,credit_limit_minor,payment_terms_days,currency,state) VALUES (?,?,?,?,?,?,?)')->execute($values);
        }
        return ['company_id'=>$companyId,'minimum_order_minor'=>$values[1],'minimum_order_qty'=>$values[2],'credit_limit_minor'=>$values[3],'payment_terms_days'=>$values[4],'currency'=>$currency,'state'=>'active'];
    }

    /** @param array<int,array<string,mixed>> $lines @return array<string,mixed> */
    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array<string,mixed>
     */ public function validateBulk(string $companyId, int $userId, array $lines, string $key): array
    {
        if ($key==='') throw new RuntimeException('Idempotency-Key is required');
        $pdo=Database::connection();
        $member=$pdo->prepare('SELECT id FROM company_members WHERE company_id=? AND user_id=? AND status=?'); $member->execute([$companyId,$userId,'active']); if ($member->fetchColumn()===false) throw new RuntimeException('User is not a company member');
        if (count($lines) < 1) throw new RuntimeException('At least one line is required');
        if (count($lines) > 200) throw new RuntimeException('Bulk order is limited to 200 lines');
         $old=$pdo->prepare('SELECT company_id,payload_json FROM b2b_audit_events WHERE idempotency_key=?'); $old->execute([$key]); $oldRow=$old->fetch(\PDO::FETCH_ASSOC); if (is_array($oldRow)) { if (($oldRow['company_id'] ?? null) !== $companyId) throw new RuntimeException('Idempotency key conflicts with another company'); $decoded=json_decode(is_string($oldRow['payload_json'] ?? null) ? $oldRow['payload_json'] : '',true); if (is_array($decoded)) return $this->row($decoded); }
        $terms=$pdo->prepare('SELECT * FROM b2b_company_terms WHERE company_id=? AND state=?'); $terms->execute([$companyId,'active']); $term=$terms->fetch(\PDO::FETCH_ASSOC); $currency=is_array($term)?$this->string($term['currency']??'VND'):'VND'; $total=0; $qtyTotal=0; $errors=[]; $clean=[];
         foreach ($lines as $index=>$line) { $variant=$this->string($line['variant_id']??null); $qty=$this->integer($line['qty']??0); if($variant===''||$qty<1||$qty>10000){$errors[]=['line'=>$index,'code'=>'invalid_line'];continue;} $q=$pdo->prepare('SELECT unit_price_minor,minimum_qty FROM b2b_customer_price_tiers t JOIN b2b_customer_price_lists l ON l.id=t.price_list_id WHERE l.company_id=? AND l.currency=? AND l.state=? AND t.variant_id=? AND t.minimum_qty<=? ORDER BY t.minimum_qty DESC LIMIT 1'); $q->execute([$companyId,$currency,'active',$variant,$qty]); $tier=$q->fetch(\PDO::FETCH_ASSOC); if(!is_array($tier)){ $errors[]=['line'=>$index,'code'=>'no_customer_price']; continue;} $unit=$this->integer($tier['unit_price_minor']??0); if($unit<0||$unit>Money::MAX_MINOR||$qty>intdiv(Money::MAX_MINOR,max(1,$unit))){$errors[]=['line'=>$index,'code'=>'amount_overflow'];continue;} $lineTotal=$unit*$qty; if($lineTotal>Money::MAX_MINOR-$total){$errors[]=['line'=>$index,'code'=>'amount_overflow'];continue;} $total+=$lineTotal; $qtyTotal+=$qty; $clean[]=['variant_id'=>$variant,'qty'=>$qty,'unit_price_minor'=>$unit,'line_total_minor'=>$lineTotal]; }
        $minimumMinor=is_array($term)?$this->integer($term['minimum_order_minor']??0):0; $minimumQty=is_array($term)?$this->integer($term['minimum_order_qty']??0):0; if($total<$minimumMinor)$errors[]=['code'=>'minimum_order_minor','required'=>$minimumMinor,'actual'=>$total]; if($qtyTotal<$minimumQty)$errors[]=['code'=>'minimum_order_qty','required'=>$minimumQty,'actual'=>$qtyTotal]; $result=['valid'=>$errors===[],'currency'=>$currency,'lines'=>$clean,'total_minor'=>$total,'total_qty'=>$qtyTotal,'errors'=>$errors];
        $pdo->prepare('INSERT INTO b2b_audit_events (id,company_id,subject_type,subject_id,event,actor_id,idempotency_key,payload_json,created_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([Ids::new(),$companyId,'bulk_order','bulk','validated',$userId,$key,json_encode($result,JSON_THROW_ON_ERROR),date('Y-m-d H:i:s')]); return $result;
    }

    /** @return array<string,mixed> */ private function row(mixed $value): array { if(!is_array($value)) throw new RuntimeException('B2B evidence unavailable'); $out=[]; foreach($value as $k=>$v) if(is_string($k))$out[$k]=$v; return $out; }
    private function string(mixed $value): string { return is_string($value)?$value:''; }
    private function integer(mixed $value): int { return is_int($value)?$value:(is_numeric($value)?(int)$value:0); }
}
