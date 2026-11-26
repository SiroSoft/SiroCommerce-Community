<?php
declare(strict_types=1);
namespace App\Resources;
use App\Support\Arr;
use App\Support\ProviderEvidence;
use Siro\Core\Resource;
final class InvoiceEvidenceResource extends Resource
{
    public function toArray(): array
    {
        $evidence = json_decode(Arr::str($this->data, 'evidence_json', '{}'), true);
        return ['invoice_id' => Arr::str($this->data, 'id'), 'currency' => Arr::str($this->data, 'currency'), 'minor_unit' => Arr::int($this->data, 'minor_unit'), 'amount_minor' => Arr::int($this->data, 'amount_minor'), 'evidence' => is_array($evidence) ? ProviderEvidence::redact(Arr::row($evidence)) : []];
    }
}
