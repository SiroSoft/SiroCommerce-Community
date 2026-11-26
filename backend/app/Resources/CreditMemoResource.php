<?php
declare(strict_types=1);
namespace App\Resources;
use App\Support\ProviderEvidence;
use App\Support\Arr;
use Siro\Core\Resource;
final class CreditMemoResource extends Resource
{
    public function toArray(): array
    {
        $out = array_intersect_key($this->data, array_flip(['id','number','invoice_id','order_id','payment_id','refund_id','state','currency','minor_unit','amount_minor','reason','created_at']));
        if (isset($this->data['evidence_json']) && is_string($this->data['evidence_json'])) {
            $evidence = json_decode($this->data['evidence_json'], true);
            if (is_array($evidence)) $out['evidence'] = ProviderEvidence::redact(Arr::row($evidence));
        }
        return $out;
    }
}
