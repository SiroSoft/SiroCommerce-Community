<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\Company;
use App\Models\CompanyApprovalScope;
use App\Models\CompanyContract;
use App\Models\CompanyCreditAccount;
use App\Models\CompanyMember;
use App\Models\CompanyOrder;
use App\Models\CompanyOrderApproval;
use App\Models\CompanyOrderWorkflow;
use App\Models\CompanyOrderTransition;
use App\Models\CompanyCreditReservation;
use App\Models\CompanyCreditTerms;
use App\Models\Order;
use App\Modules\B2B\Contracts\B2BCommercialStore;
use App\Repositories\Concerns\CreatesModels;
use App\Support\Ids;
use App\Modules\Shared\Domain\Money;
use Siro\Core\Database;

final class B2BAdapter implements \App\Modules\B2B\Contracts\B2BWorkflowStore, \App\Modules\B2B\Contracts\B2BQuoteStore
{
    use CreatesModels;

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    public function createCompany(array $data): array { $data['id'] = Ids::new(); return $this->normalise(self::createRow(Company::class, $data)); }
    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    public function addMember(array $data): array { $data['id'] = Ids::new(); return $this->normalise(self::createRow(CompanyMember::class, $data)); }
    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    public function addScope(array $data): array { $data['id'] = Ids::new(); return $this->normalise(self::createRow(CompanyApprovalScope::class, $data)); }
    /** @return array<string, mixed>|null */
    public function order(string $orderId): ?array { $link = CompanyOrder::query()->where('order_id', $orderId)->first(); $order = Order::find($orderId); if ($link === null || $order === null) return null; $source = $order->toArray(); $safe = []; foreach (['id','number','currency','grand_total_minor','state'] as $field) if (array_key_exists($field, $source)) $safe[$field] = $source[$field]; return array_merge($safe, $link->toArray()); }
    /** @return array<string, mixed>|null */
    public function member(string $companyId, int $userId): ?array { return $this->row(CompanyMember::query()->where('company_id', $companyId)->where('user_id', $userId)->where('status', 'active')->first()); }
    /** @return array<string, mixed>|null */
    public function scope(string $companyId, string $scope, ?string $channelId): ?array { $query = CompanyApprovalScope::query()->where('company_id', $companyId)->where('scope', $scope); $query = $channelId === null ? $query->whereNull('channel_id') : $query->where('channel_id', $channelId); return $this->row($query->first()); }
    /** @return array<string, mixed>|null */
    public function approvalByKey(string $key): ?array { return $this->row(CompanyOrderApproval::query()->where('idempotency_key', $key)->first()); }
    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
  public function approval(array $data): array { return $this->normalise($this->transactionArray(function () use ($data): array {
      $key = is_string($data['idempotency_key'] ?? null) ? $data['idempotency_key'] : '';
      $existing = $this->lockedRow(CompanyOrderApproval::query()->where('idempotency_key', $key)->lockForUpdate()->first());
      if ($existing !== null) return $existing;
      $orderId = is_string($data['order_id'] ?? null) ? $data['order_id'] : '';
      $order = Order::find($orderId);
      if ($order === null) throw new \RuntimeException('B2B order not found');
      $scope = CompanyApprovalScope::query()->where('company_id', $data['company_id'] ?? '')->where('scope', 'order.approve')->where('active', 1)->lockForUpdate()->first();
      if ($scope !== null) {
          $limit = $scope['approval_limit_minor'] ?? null; $total = $order->getAttribute('grand_total_minor');
          if (is_numeric($limit) && is_numeric($total) && (int)$total > (int)$limit) throw new \RuntimeException('Order exceeds approval limit');
      }
       $data['id'] = Ids::new(); $approval = self::createRow(CompanyOrderApproval::class, $data); Database::connection()->prepare('INSERT INTO b2b_audit_events (id,company_id,subject_type,subject_id,event,actor_id,idempotency_key,payload_json,created_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([Ids::new(),$this->stringValue($data['company_id']??null),'order',$this->stringValue($data['order_id']??null),'approved',$this->toInt($data['user_id']??null),'approval:'.$key,json_encode(['scope'=>$this->stringValue($data['scope']??'order.approve'),'status'=>$this->stringValue($data['status']??'approved')],JSON_THROW_ON_ERROR),date('Y-m-d H:i:s')]); return $approval;
  })); }
    /** @param array<string, mixed> $data @return array<string, mixed> */
     public function creditAccount(array $data): array { $result = $this->transactionArray(function () use ($data): array { $terms = ['id'=>Ids::new(), 'company_id'=>is_string($data['company_id']??null)?$data['company_id']:'', 'payment_terms_days'=>is_numeric($data['payment_terms_days']??null)?(int)$data['payment_terms_days']:0, 'evidence_json'=>is_string($data['evidence_json']??null)?$data['evidence_json']:'{}']; unset($data['payment_terms_days'], $data['evidence_json']); $data['id'] = Ids::new(); $row = self::createRow(CompanyCreditAccount::class, $data); self::createRow(CompanyCreditTerms::class, $terms); return $this->normalise($row); }); return $this->normalise($result); }
    /** @return array<string, mixed>|null */
    public function creditAccountForCompany(string $companyId): ?array { return $this->row(CompanyCreditAccount::query()->where('company_id', $companyId)->first()); }
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function contract(array $data): array { $data['id'] = Ids::new(); return self::createRow(CompanyContract::class, $data); }
    /** @return array<string, mixed>|null */
    public function contractById(string $contractId): ?array { return $this->row(CompanyContract::find($contractId)); }
    /** @return array<string, mixed>|null */
    public function contractApprovalByKey(string $key): ?array { return $this->row(\App\Models\CompanyContractApproval::query()->where('idempotency_key', $key)->first()); }
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function contractApproval(array $data): array { $data['id'] = Ids::new(); return self::createRow(\App\Models\CompanyContractApproval::class, $data); }
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function activateContract(string $contractId, array $data): array { $model = CompanyContract::find($contractId); if ($model === null) throw new \RuntimeException('B2B contract not found'); $model->fill($data); $model->save(); return $model->toArray(); }
    public function workflow(string $orderId): ?array { return $this->row(CompanyOrderWorkflow::find($orderId)); }
    public function transitionByKey(string $key): ?array { return $this->row(CompanyOrderTransition::query()->where('idempotency_key', $key)->first()); }
       public function transitionOrder(array $data): array { return $this->normalise($this->transactionArray(function () use ($data): array { $key = is_string($data['idempotency_key'] ?? null) ? $data['idempotency_key'] : ''; $existingTransition = $this->lockedRow(CompanyOrderTransition::query()->where('idempotency_key', $key)->lockForUpdate()->first()); if ($existingTransition !== null) return $existingTransition; $locked = $this->lockedRow(CompanyOrderWorkflow::query()->where('order_id', $data['order_id'])->lockForUpdate()->first()); $workflow = isset($locked['order_id']) && is_string($locked['order_id']) ? CompanyOrderWorkflow::find($locked['order_id']) : null; $stateValue = $workflow?->getAttribute('state'); $fromState = $workflow === null ? 'draft' : (is_string($stateValue) ? $stateValue : ''); if (($data['from_state'] ?? 'draft') !== $fromState) throw new \RuntimeException('B2B workflow state changed'); if ($workflow === null) $workflow = new CompanyOrderWorkflow(); $toState = is_string($data['to_state'] ?? null) ? $data['to_state'] : ''; $workflow->fill(['order_id'=>$data['order_id'],'company_id'=>$data['company_id'],'state'=>$toState,'submitted_by'=>$toState==='submitted'?$data['user_id']:$workflow->getAttribute('submitted_by'),'approved_by'=>$toState==='approved'?$data['user_id']:$workflow->getAttribute('approved_by'),'rejected_by'=>$toState==='rejected'?$data['user_id']:$workflow->getAttribute('rejected_by'),'rejection_reason'=>$data['reason'] ?? $workflow->getAttribute('rejection_reason')]); $workflow->save(); $data['id']=Ids::new(); $transition=self::createRow(CompanyOrderTransition::class,$data); Database::connection()->prepare('INSERT INTO b2b_audit_events (id,company_id,subject_type,subject_id,event,actor_id,idempotency_key,payload_json,created_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([Ids::new(),$this->stringValue($data['company_id']??null),'order',$this->stringValue($data['order_id']??null),'order_'.$toState,$this->toInt($data['user_id']??null),'workflow:'.$key,json_encode(['from_state'=>$fromState,'to_state'=>$toState,'reason'=>$this->stringValue($data['reason']??null)],JSON_THROW_ON_ERROR),date('Y-m-d H:i:s')]); return $this->normalise($transition); })); }
       public function reserveCredit(string $companyId, string $orderId, int $amount, string $currency, string $key): array { if ($amount <= 0) throw new \RuntimeException('Credit reservation amount must be positive'); $result = $this->transactionArray(function () use ($companyId,$orderId,$amount,$currency,$key): array { $existingByKey = $this->lockedRow(CompanyCreditReservation::query()->where('idempotency_key',$key)->lockForUpdate()->first()); if ($existingByKey !== null) return $existingByKey; $existing=$this->lockedRow(CompanyCreditReservation::query()->where('order_id',$orderId)->lockForUpdate()->first()); if ($existing !== null) { if (($existing['state'] ?? null) === 'reserved') return $existing; throw new \RuntimeException('Credit reservation has been released'); } $candidate=$this->lockedRow(CompanyCreditAccount::query()->where('company_id',$companyId)->lockForUpdate()->first()); if ($candidate === null || ($candidate['status'] ?? null) !== 'active') throw new \RuntimeException('Company credit is not active'); if (($candidate['currency'] ?? null) !== $currency) throw new \RuntimeException('Credit currency mismatch'); $reserved=0; foreach ($this->rows(CompanyCreditReservation::query()->where('company_id',$companyId)->where('state','reserved')->get()) as $item) if (is_numeric($item['amount_minor'] ?? null)) $reserved+=(int)$item['amount_minor']; $limit = is_numeric($candidate['limit_minor'] ?? null) ? (int)$candidate['limit_minor'] : 0; if ($reserved+$amount>$limit) throw new \RuntimeException('Insufficient company credit'); return $this->normalise(self::createRow(CompanyCreditReservation::class,['id'=>Ids::new(),'company_id'=>$companyId,'order_id'=>$orderId,'currency'=>$currency,'amount_minor'=>$amount,'state'=>'reserved','idempotency_key'=>$key])); }); return $this->normalise($result); }
      public function releaseCredit(string $orderId, string $key): array { $result = $this->transactionArray(function () use ($orderId,$key): array { $locked=$this->lockedRow(CompanyCreditReservation::query()->where('order_id',$orderId)->lockForUpdate()->first()); if($locked === null) return ['order_id'=>$orderId,'state'=>'released','idempotency_key'=>$key]; $candidate=isset($locked['id']) && is_string($locked['id']) ? CompanyCreditReservation::find($locked['id']) : null; if($candidate === null) return ['order_id'=>$orderId,'state'=>'released','idempotency_key'=>$key]; if($candidate->getAttribute('state')==='reserved'){ $candidate->fill(['state'=>'released']); $candidate->save(); } return $this->normalise($candidate->toArray()); }); return $this->normalise($result); }
     public function creditEvidence(string $companyId): array { $account=$this->creditAccountForCompany($companyId); if($account===null) throw new \RuntimeException('Company credit account not found'); $reserved=0; foreach($this->rows(CompanyCreditReservation::query()->where('company_id',$companyId)->where('state','reserved')->get()) as $item) $reserved+=$this->toInt($item['amount_minor'] ?? null); $terms=CompanyCreditTerms::query()->where('company_id',$companyId)->first(); $limit=$this->toInt($account['limit_minor']??null); return ['company_id'=>$companyId,'currency'=>is_string($account['currency']??null)?$account['currency']:'VND','credit_limit_minor'=>$limit,'reserved_minor'=>$reserved,'available_balance_minor'=>$limit-$reserved,'payment_terms_days'=>$terms instanceof CompanyCreditTerms?$this->toInt($terms->getAttribute('payment_terms_days')):0,'status'=>is_string($account['status']??null)?$account['status']:'pending']; }
    /** @return array<string, mixed>|null */
    private function row(mixed $value): ?array { if (is_array($value)) return array_filter($value, static fn (mixed $v, mixed $k): bool => is_string($k), ARRAY_FILTER_USE_BOTH); if (is_object($value) && method_exists($value, 'toArray')) { $row = $value->toArray(); return is_array($row) ? $this->row($row) : null; } return null; }
     /**
      * @param callable(): array<string, mixed> $callback
      * @return array<string, mixed>
      */
     private function transactionArray(callable $callback): array { return $this->normalise(Database::transaction($callback)); }
     /** @return array<string, mixed>|null */
     private function lockedRow(mixed $value): ?array { return $this->row($value); }
     /** @return array<int, array<string, mixed>> */
     private function rows(mixed $value): array { if (!is_array($value)) return []; $rows=[]; foreach ($value as $item) { $row=$this->row($item); if ($row !== null) $rows[]=$row; } return $rows; }
     private function toInt(mixed $value): int { return is_int($value) ? $value : (is_numeric($value) ? (int)$value : 0); }
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createQuote(array $data): array
    {
        $company = is_string($data['company_id'] ?? null) ? $data['company_id'] : '';
        $currency = is_string($data['currency'] ?? null) ? strtoupper($data['currency']) : '';
        $key = is_string($data['idempotency_key'] ?? null) ? $data['idempotency_key'] : '';
        $userId = $this->toInt($data['user_id'] ?? null); $lines = $this->quoteLines($data['lines'] ?? null);
        // Empty lines are allowed: the console creates a draft shell first and
        // adds negotiated lines afterwards. Line validation still applies
        // per line below, and totals stay zero until lines exist.
        if ($company === '' || $key === '' || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) throw new \RuntimeException('Quote currency and Idempotency-Key are required');
        return $this->transactionArray(function () use ($company, $currency, $key, $userId, $lines, $data): array {
            if ($this->member($company, $userId) === null) throw new \RuntimeException('User is not a company member');
            $existing = Database::connection()->prepare('SELECT * FROM b2b_quotes WHERE idempotency_key=? AND company_id=?'); $existing->execute([$key, $company]); $row = $existing->fetch(\PDO::FETCH_ASSOC);
            if (is_array($row)) return $this->quoteEvidence($this->normalise($row));
            $normalised = []; $total = 0; $pdo = Database::connection();
            foreach ($lines as $line) {
                $variant = $this->stringValue($line['variant_id'] ?? null); $qty = $this->toInt($line['qty'] ?? null);
                if ($variant === '' || $qty < 1) throw new \RuntimeException('Invalid quote line');
                $catalog = $pdo->prepare("SELECT i.id FROM company_catalog_items i JOIN company_catalogs c ON c.id=i.catalog_id WHERE i.variant_id=? AND i.minimum_qty<=? AND c.currency=? AND c.state='active' AND (c.company_id=? OR c.scope='shared') LIMIT 1"); $catalog->execute([$variant,$qty,$currency,$company]);
                if ($catalog->fetchColumn() === false) throw new \RuntimeException('Variant is not in the company catalog');
                $q = $pdo->prepare("SELECT unit_price_minor FROM company_negotiated_prices WHERE company_id=? AND variant_id=? AND currency=? AND state='active' AND minimum_qty<=? ORDER BY minimum_qty DESC LIMIT 1"); $q->execute([$company, $variant, $currency, $qty]); $price = $q->fetch(\PDO::FETCH_ASSOC);
                if (!is_array($price)) throw new \RuntimeException('No negotiated price for variant');
                 $unit = $this->toInt($price['unit_price_minor'] ?? null); if ($unit < 0 || $unit > Money::MAX_MINOR || $qty > intdiv(Money::MAX_MINOR, max(1, $unit))) throw new \RuntimeException('Quote amount exceeds the storage limit'); $lineTotal = $unit * $qty; if ($lineTotal > Money::MAX_MINOR - $total) throw new \RuntimeException('Quote amount exceeds the storage limit'); $total += $lineTotal;
                $normalised[] = ['variant_id'=>$variant,'qty'=>$qty,'unit_price_minor'=>$unit,'line_total_minor'=>$lineTotal,'currency'=>$currency,'pricing_source'=>'negotiated'];
            }
            $id = Ids::new(); $totals = ['subtotal_minor'=>$total,'total_minor'=>$total,'currency'=>$currency];
            $pdo->prepare('INSERT INTO b2b_quotes (id,company_id,currency,state,lines_json,totals_json,expires_at,purchase_order_number,payment_terms_days,idempotency_key,created_by,current_revision) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$company,$currency,'draft',json_encode($normalised, JSON_THROW_ON_ERROR),json_encode($totals, JSON_THROW_ON_ERROR),$data['expires_at'] ?? null,$data['purchase_order_number'] ?? null,$this->toInt($data['payment_terms_days'] ?? null),$key,$userId,1]);
            $this->insertRevision($id, 1, 'draft', $normalised, $totals, $userId);
            return ['id'=>$id,'company_id'=>$company,'currency'=>$currency,'state'=>'draft','revision'=>1,'lines'=>$normalised,'subtotal_minor'=>$total,'total_minor'=>$total,'expires_at'=>$data['expires_at'] ?? null,'created_by'=>$userId];
        });
    }
    /** @return array<string,mixed>|null */
    public function quote(string $quoteId, int $userId): ?array { $s=Database::connection()->prepare('SELECT q.* FROM b2b_quotes q INNER JOIN company_members m ON m.company_id=q.company_id WHERE q.id=? AND m.user_id=? AND m.status=?'); $s->execute([$quoteId,$userId,'active']); $r=$s->fetch(\PDO::FETCH_ASSOC); return is_array($r)?$this->quoteEvidence($this->normalise($r)):null; }
    /** @return array<string,mixed>|null */
    public function quoteTransitionByKey(string $key): ?array { $s=Database::connection()->prepare('SELECT * FROM b2b_quote_transitions WHERE idempotency_key=?'); $s->execute([$key]); $r=$s->fetch(\PDO::FETCH_ASSOC); if(!is_array($r)) return null; $result=$this->normalise($r); $extra=json_decode($this->stringValue($result['result_json']??null),true); if(is_array($extra)&&$extra!==[]) { return $this->normalise($extra); } return $result; }
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function transitionQuote(array $data): array
    {
        return $this->transactionArray(function () use ($data): array {
            $pdo=Database::connection(); $key=$this->stringValue($data['idempotency_key']??null);
            $s=$pdo->prepare('SELECT * FROM b2b_quotes WHERE id=?'.$this->lockSuffix()); $s->execute([$this->stringValue($data['quote_id']??null)]); $quote=$s->fetch(\PDO::FETCH_ASSOC); if(!is_array($quote)) throw new \RuntimeException('B2B quote not found'); $quote=$this->normalise($quote);
              $company=$this->stringValue($quote['company_id']??null); $uid=$this->toInt($data['user_id']??null); $member=$this->member($company,$uid); if($member===null) throw new \RuntimeException('User is not a company member');
             $from=$this->stringValue($quote['state']??null); $action=$this->stringValue($data['action']??null); $to=['submit'=>'submitted','approve'=>'approved','accept'=>'accepted','expire'=>'expired'][$action] ?? '';
             $allowed=['draft'=>['submitted'],'submitted'=>['approved','expired'],'approved'=>['accepted','expired'],'accepted'=>['converted','expired']]; if(!in_array($to,$allowed[$from]??[],true)) throw new \RuntimeException('Invalid B2B quote transition');
              if($action==='approve') { if(!in_array($this->stringValue($member['role']??null),['approver','admin'],true)) throw new \RuntimeException('User cannot approve for this company'); $scope=$this->scope($company,'quote.approve',null); if($scope===null || $this->toInt($scope['active']??0)!==1) throw new \RuntimeException('Approval scope is not granted'); }
             $old=$pdo->prepare('SELECT * FROM b2b_quote_transitions WHERE idempotency_key=?'); $old->execute([$key]); $oldRow=$old->fetch(\PDO::FETCH_ASSOC); if(is_array($oldRow)) return $this->normalise($oldRow);
            if($action==='submit') $this->reserveQuoteCredit($quote,$key);
            $quoteId=$this->stringValue($quote['id']??null); if($action==='expire') $this->releaseQuoteCredit($quoteId);
            $now=date('Y-m-d H:i:s'); $lines=$this->quoteLinesFromJson($quote['lines_json']??null); $totals=$this->quoteTotalsFromJson($quote['totals_json']??null); $revision=$this->toInt($quote['current_revision']??1)+1; $pdo->prepare('UPDATE b2b_quotes SET state=? WHERE id=?')->execute([$to,$quoteId]); $this->insertRevision($quoteId,$revision,$to,$lines,$totals,$uid);
             $id=Ids::new(); $reason=$this->stringValue($data['reason']??null); $pdo->prepare('INSERT INTO b2b_quote_transitions (id,quote_id,from_state,to_state,user_id,idempotency_key,reason,created_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$id,$quoteId,$from,$to,$uid,$key,$reason,$now]); $pdo->prepare('INSERT INTO b2b_audit_events (id,company_id,subject_type,subject_id,event,actor_id,idempotency_key,payload_json,created_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([Ids::new(),$company,'quote',$quoteId,'quote_'.$to,$uid,'quote:'.$key,json_encode(['from_state'=>$from,'to_state'=>$to,'reason'=>$reason],JSON_THROW_ON_ERROR),$now]); return ['id'=>$id,'quote_id'=>$quoteId,'from_state'=>$from,'to_state'=>$to,'user_id'=>$uid,'idempotency_key'=>$key];
        });
    }
    /** @return array<string,mixed> */
    public function convertQuote(string $quoteId, int $userId, string $key): array
    {
        return $this->transactionArray(function () use ($quoteId,$userId,$key): array {
            $pdo=Database::connection();
             $s=$pdo->prepare('SELECT * FROM b2b_quotes WHERE id=?'.$this->lockSuffix()); $s->execute([$quoteId]); $q=$s->fetch(\PDO::FETCH_ASSOC); if(!is_array($q)) throw new \RuntimeException('B2B quote not found'); $q=$this->normalise($q); if($this->stringValue($q['state']??null)!=='accepted') throw new \RuntimeException('Only an accepted quote can be converted'); if($this->member($this->stringValue($q['company_id']??null),$userId)===null) throw new \RuntimeException('User is not a company member'); $existing=$pdo->prepare('SELECT result_json FROM b2b_quote_transitions WHERE idempotency_key=?'.$this->lockSuffix()); $existing->execute([$key]); $old=$existing->fetchColumn(); if(is_string($old)&&$old!=='') { $decoded=json_decode($old,true); if(is_array($decoded)) return $this->normalise($decoded); }
            $orderId=Ids::new(); $totals=$this->quoteTotalsFromJson($q['totals_json']??null); $total=$this->toInt($totals['total_minor']??0); $now=date('Y-m-d H:i:s'); $pdo->prepare('INSERT INTO orders (id,number,currency,subtotal_minor,grand_total_minor,state,idempotency_key,created_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$orderId,Ids::orderNumber(),$this->stringValue($q['currency']??null),$total,$total,'pending','quote:'.$quoteId,$now]);
            $lines=$this->quoteLinesFromJson($q['lines_json']??null); foreach($lines as $line) $pdo->prepare('INSERT INTO order_lines (id,order_id,variant_id,sku,name,qty,unit_price_minor) VALUES (?,?,?,?,?,?,?)')->execute([Ids::new(),$orderId,$line['variant_id'],'','', $line['qty'],$line['unit_price_minor']]);
            $pdo->prepare("UPDATE b2b_quote_credit_reservations SET state='consumed' WHERE quote_id=? AND state='reserved'")->execute([$quoteId]); $pdo->prepare('UPDATE b2b_quotes SET state=?,converted_order_id=? WHERE id=?')->execute(['converted',$orderId,$quoteId]); $id=Ids::new(); $result=['id'=>$id,'quote_id'=>$quoteId,'to_state'=>'converted','order_id'=>$orderId,'idempotency_key'=>$key]; $pdo->prepare('INSERT INTO b2b_quote_transitions (id,quote_id,from_state,to_state,user_id,idempotency_key,reason,result_json,created_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$id,$quoteId,'accepted','converted',$userId,$key,'',json_encode($result,JSON_THROW_ON_ERROR),$now]); return $result;
        });
    }
    /** @param array<string,mixed> $quote */
    private function reserveQuoteCredit(array $quote, string $key): void { $pdo=Database::connection(); $s=$pdo->prepare('SELECT * FROM company_credit_accounts WHERE company_id=?'.$this->lockSuffix()); $s->execute([$this->stringValue($quote['company_id']??null)]); $account=$s->fetch(\PDO::FETCH_ASSOC); if(!is_array($account)||$this->stringValue($account['status']??null)!=='active'||$this->stringValue($account['currency']??null)!==$this->stringValue($quote['currency']??null)) throw new \RuntimeException('Company credit is not active'); $totals=json_decode($this->stringValue($quote['totals_json']??null),true); $totals=is_array($totals)?$totals:[]; $amount=$this->toInt($totals['total_minor']??0); $s=$pdo->prepare("SELECT COALESCE(SUM(amount_minor),0) FROM b2b_quote_credit_reservations WHERE company_id=? AND state='reserved'"); $s->execute([$this->stringValue($quote['company_id']??null)]); if($this->toInt($s->fetchColumn())+$amount>$this->toInt($account['limit_minor']??0)) throw new \RuntimeException('Insufficient company credit'); $pdo->prepare('INSERT INTO b2b_quote_credit_reservations (id,quote_id,company_id,currency,amount_minor,state,idempotency_key,created_at) VALUES (?,?,?,?,?,?,?,?)')->execute([Ids::new(),$this->stringValue($quote['id']??null),$this->stringValue($quote['company_id']??null),$this->stringValue($quote['currency']??null),$amount,'reserved','quote:'.$key,date('Y-m-d H:i:s')]); }
    private function releaseQuoteCredit(string $quoteId): void { $s=Database::connection()->prepare("UPDATE b2b_quote_credit_reservations SET state='released' WHERE quote_id=? AND state='reserved'"); $s->execute([$quoteId]); }
    private function lockSuffix(): string { $driver=Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME); return is_string($driver)&&strtolower($driver)==='mysql' ? ' FOR UPDATE' : ''; }
    private function stringValue(mixed $value): string { return is_string($value) ? $value : ''; }
    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed> $totals
     */
    private function insertRevision(string $quoteId,int $revision,string $state,array $lines,array $totals,int $userId): void { Database::connection()->prepare('INSERT INTO b2b_quote_revisions (id,quote_id,revision,state,lines_json,totals_json,snapshot_hash,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([Ids::new(),$quoteId,$revision,$state,json_encode($lines,JSON_THROW_ON_ERROR),json_encode($totals,JSON_THROW_ON_ERROR),hash('sha256',json_encode([$lines,$totals],JSON_THROW_ON_ERROR)),$userId,date('Y-m-d H:i:s')]); Database::connection()->prepare('UPDATE b2b_quotes SET current_revision=? WHERE id=?')->execute([$revision,$quoteId]); }
    /** @return array<int, array<string, mixed>> */
    private function quoteLines(mixed $value): array { if(!is_array($value)) return []; $lines=[]; foreach($value as $line) { if(!is_array($line)) throw new \RuntimeException('Invalid quote line'); $lines[]=$this->normalise($line); } return $lines; }
    /** @return array<int, array<string, mixed>> */
    private function quoteLinesFromJson(mixed $value): array { $decoded=json_decode($this->stringValue($value),true); return $this->quoteLines($decoded); }
    /** @return array<string, mixed> */
    private function quoteTotalsFromJson(mixed $value): array { $decoded=json_decode($this->stringValue($value),true); return is_array($decoded)?$this->normalise($decoded):[]; }
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function quoteEvidence(array $row): array { $r=$this->normalise($row); $r['lines']=$this->quoteLinesFromJson($r['lines_json']??null); $r['totals']=$this->quoteTotalsFromJson($r['totals_json']??null); return $r; }
    /** @return array<string, mixed> */
    private function normalise(mixed $value): array { if(!is_array($value)) throw new \RuntimeException('B2B transaction did not return evidence'); $result=[]; foreach($value as $key=>$item) if(is_string($key)) $result[$key]=$item; return $result; }
}
