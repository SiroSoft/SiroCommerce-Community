<?php
declare(strict_types=1);
namespace App\Modules\B2B\Application;

use App\Domain\Money;
use App\Support\Ids;
use Siro\Core\Database;
use RuntimeException;

final class B2BEnterpriseService
{
    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function createCatalog(string $companyId, array $data): array
    {
        $code = trim($this->stringValue($data['code'] ?? null)); $name = trim($this->stringValue($data['name'] ?? null)); $currency = $this->stringValue($data['currency'] ?? 'VND');
        if ($code === '' || $name === '' || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) throw new RuntimeException('Catalog code, name, and currency are required');
        $scope = $this->stringValue($data['scope'] ?? 'company');
        if (!in_array($scope, ['company', 'shared'], true)) throw new RuntimeException('Catalog scope is invalid');
        $id = Ids::new(); Database::connection()->prepare('INSERT INTO company_catalogs (id, company_id, code, name, currency, scope, state) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$id, $companyId, $code, $name, $currency, $scope, 'active']);
        return ['id'=>$id,'company_id'=>$companyId,'code'=>$code,'name'=>$name,'currency'=>$currency,'scope'=>$scope,'state'=>'active'];
    }

    /** @return array<string,mixed> */
    public function addCatalogItem(string $companyId, string $catalogId, string $variantId, int $minimumQty = 1): array
    {
        if ($minimumQty < 1) throw new RuntimeException('minimum_qty must be positive');
        $check = Database::connection()->prepare('SELECT id FROM company_catalogs WHERE id = ? AND company_id = ? AND state = ?'); $check->execute([$catalogId,$companyId,'active']);
        if ($check->fetchColumn() === false) throw new RuntimeException('Catalog is not assigned to this company');
        $id = Ids::new(); Database::connection()->prepare('INSERT INTO company_catalog_items (id,catalog_id,variant_id,minimum_qty) VALUES (?,?,?,?)')->execute([$id,$catalogId,$variantId,$minimumQty]);
        return ['id'=>$id,'catalog_id'=>$catalogId,'variant_id'=>$variantId,'minimum_qty'=>$minimumQty];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function setNegotiatedPrice(string $companyId, array $data): array
    {
        $variant = trim($this->stringValue($data['variant_id'] ?? null)); $currency = $this->stringValue($data['currency'] ?? 'VND'); $price = $this->intValue($data['unit_price_minor'] ?? -1); $minimum = $this->intValue($data['minimum_qty'] ?? 1);
        if ($variant === '' || $price < 0 || $minimum < 1 || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) throw new RuntimeException('Invalid negotiated price');
        $id = Ids::new(); Database::connection()->prepare('INSERT INTO company_negotiated_prices (id,company_id,variant_id,currency,unit_price_minor,minimum_qty,state,evidence_json) VALUES (?,?,?,?,?,?,?,?)')->execute([$id,$companyId,$variant,$currency,$price,$minimum,'active',json_encode(['source'=>'negotiated_contract'], JSON_THROW_ON_ERROR)]);
        return ['id'=>$id,'company_id'=>$companyId,'variant_id'=>$variant,'currency'=>$currency,'unit_price_minor'=>$price,'minimum_qty'=>$minimum,'state'=>'active'];
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array<string,mixed>
     */
    public function createQuote(string $companyId, int $userId, string $currency, array $lines, string $key, ?string $expiresAt = null, ?string $purchaseOrderNumber = null, int $paymentTermsDays = 0): array
    {
        if ($key === '' || preg_match('/^[A-Z]{3}$/D', $currency) !== 1 || $lines === [] || $paymentTermsDays < 0) throw new RuntimeException('Quote currency, lines, and Idempotency-Key are required');
        $pdo = Database::connection();
        $member = $pdo->prepare('SELECT role FROM company_members WHERE company_id = ? AND user_id = ? AND status = ?'); $member->execute([$companyId, $userId, 'active']);
        if ($member->fetchColumn() === false) throw new RuntimeException('User is not a company member');
         $existing = $pdo->prepare('SELECT * FROM b2b_quotes WHERE idempotency_key = ?'); $existing->execute([$key]); $row = $existing->fetch(\PDO::FETCH_ASSOC); if (is_array($row)) { if (($row['company_id'] ?? null) !== $companyId || ($row['currency'] ?? null) !== $currency || ($row['purchase_order_number'] ?? null) !== $purchaseOrderNumber || (int)($row['payment_terms_days'] ?? 0) !== $paymentTermsDays) throw new RuntimeException('Idempotency key conflicts with another quote'); $storedLines=json_decode(is_string($row['lines_json'] ?? null) ? $row['lines_json'] : '', true); $sameLines=is_array($storedLines) && count($storedLines) === count($lines); if ($sameLines) foreach ($lines as $index=>$line) { if (!is_array($storedLines[$index] ?? null) || ($storedLines[$index]['variant_id'] ?? null) !== ($line['variant_id'] ?? null) || (int)($storedLines[$index]['qty'] ?? 0) !== (int)($line['qty'] ?? 0)) { $sameLines=false; break; } } if (!$sameLines) throw new RuntimeException('Idempotency key conflicts with the quote'); $clean=[]; foreach ($row as $field=>$value) if (is_string($field)) $clean[$field]=$value; return $clean; }
        $terms=$pdo->prepare('SELECT payment_terms_days FROM company_credit_terms WHERE company_id=?'); $terms->execute([$companyId]); $allowedTerms=$terms->fetchColumn();
        if ($paymentTermsDays > 0 && (!is_numeric($allowedTerms) || $paymentTermsDays > (int)$allowedTerms)) throw new RuntimeException('Payment terms exceed company terms');
        if ($paymentTermsDays > 0 && ($purchaseOrderNumber === null || trim($purchaseOrderNumber) === '')) throw new RuntimeException('Purchase order number is required for payment terms');
        $result = Database::transaction(function () use ($pdo, $companyId, $currency, $lines, $key, $expiresAt, $userId, $purchaseOrderNumber, $paymentTermsDays): array {
            $normalised=[]; $total=0;
            foreach ($lines as $line) {
                $variant=$this->stringValue($line['variant_id']??null); $qty=$this->intValue($line['qty']??0);
                if ($variant===''||$qty<1) throw new RuntimeException('Invalid quote line');
                $catalog=$pdo->prepare("SELECT i.minimum_qty FROM company_catalog_items i JOIN company_catalogs c ON c.id=i.catalog_id WHERE i.variant_id=? AND i.minimum_qty<=? AND c.state='active' AND c.currency=? AND (c.company_id=? OR c.scope='shared') ORDER BY CASE WHEN c.company_id=? THEN 0 ELSE 1 END, i.minimum_qty DESC LIMIT 1");
                $catalog->execute([$variant,$qty,$currency,$companyId,$companyId]); $membership=$catalog->fetch(\PDO::FETCH_ASSOC);
                if (!is_array($membership)) throw new RuntimeException('Variant is not in the company catalog');
                $q=$pdo->prepare('SELECT unit_price_minor,minimum_qty FROM company_negotiated_prices WHERE company_id=? AND variant_id=? AND currency=? AND state=? AND minimum_qty<=? ORDER BY minimum_qty DESC LIMIT 1'); $q->execute([$companyId,$variant,$currency,'active',$qty]); $price=$q->fetch(\PDO::FETCH_ASSOC); if (!is_array($price)) throw new RuntimeException('No negotiated price for variant');
                $unit=$this->intValue($price['unit_price_minor']??0);
                if ($unit < 0 || $unit > Money::MAX_MINOR || $qty > intdiv(Money::MAX_MINOR, max(1, $unit))) throw new RuntimeException('Quote amount exceeds the storage limit');
                $lineTotal=$unit*$qty;
                if ($lineTotal > Money::MAX_MINOR - $total) throw new RuntimeException('Quote amount exceeds the storage limit');
                $normalised[]=['variant_id'=>$variant,'qty'=>$qty,'unit_price_minor'=>$unit,'line_total_minor'=>$lineTotal,'currency'=>$currency,'pricing_source'=>'negotiated']; $total+=$lineTotal;
            }
             $id=Ids::new(); $totals=['subtotal_minor'=>$total,'total_minor'=>$total,'currency'=>$currency]; $pdo->prepare('INSERT INTO b2b_quotes (id,company_id,currency,state,lines_json,totals_json,expires_at,purchase_order_number,payment_terms_days,idempotency_key,created_by,current_revision) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$companyId,$currency,'draft',json_encode($normalised,JSON_THROW_ON_ERROR),json_encode($totals,JSON_THROW_ON_ERROR),$expiresAt,$purchaseOrderNumber,$paymentTermsDays,$key,$userId,1]);
             $pdo->prepare('INSERT INTO b2b_quote_revisions (id,quote_id,revision,state,lines_json,totals_json,snapshot_hash,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([Ids::new(),$id,1,'draft',json_encode($normalised,JSON_THROW_ON_ERROR),json_encode($totals,JSON_THROW_ON_ERROR),hash('sha256',json_encode([$normalised,$totals],JSON_THROW_ON_ERROR)),$userId,date('Y-m-d H:i:s')]);
             return ['id'=>$id,'company_id'=>$companyId,'currency'=>$currency,'state'=>'draft','revision'=>1,'lines'=>$normalised,'subtotal_minor'=>$total,'total_minor'=>$total,'expires_at'=>$expiresAt,'purchase_order_number'=>$purchaseOrderNumber,'payment_terms_days'=>$paymentTermsDays,'created_by'=>$userId];
        });
        if (!is_array($result)) throw new RuntimeException('Quote transaction returned invalid evidence');
        $clean = [];
        foreach ($result as $field => $value) if (is_string($field)) $clean[$field] = $value;
        return $clean;
    }

    private function stringValue(mixed $value): string { return is_string($value) ? $value : ''; }
    private function intValue(mixed $value): int { return is_int($value) ? $value : (is_numeric($value) ? (int)$value : 0); }
}
