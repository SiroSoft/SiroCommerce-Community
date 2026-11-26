<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Modules\Finance\Contracts\CreditMemoService;
use App\Modules\Finance\Contracts\InvoiceService;
use App\Requests\Finance\CreateCreditMemoRequest;
use App\Requests\Finance\CreateInvoiceRequest;
use App\Resources\InvoiceResource;
use App\Resources\CreditMemoResource;
use App\Resources\InvoiceEvidenceResource;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class FinanceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices, private readonly CreditMemoService $memos) {}

    public function createInvoice(CreateInvoiceRequest $request, Request $rawRequest): Response
    {
        $data = $request->validated();
        try {
            $invoice = $this->invoices->create(Arr::requiredStr($data, 'order_id'), Arr::requiredStr($data, 'payment_id'), $this->key($rawRequest));
            return $this->success(InvoiceResource::make($invoice), 'Invoice created');
        } catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function showInvoice(Request $request): Response
    {
        try {
            $invoice = $this->invoices->find(Arr::requiredParam($request, 'id'));
            return $invoice === null ? $this->error('Invoice not found', 404) : $this->success(InvoiceResource::make($invoice), 'Invoice');
        } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function showInvoiceEvidence(Request $request): Response
    {
        try {
            $invoice = $this->invoices->find(Arr::requiredParam($request, 'id'));
            return $invoice === null ? $this->error('Invoice not found', 404) : $this->success(InvoiceEvidenceResource::make($invoice), 'Invoice evidence');
        } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function issueInvoice(Request $request): Response
    {
        try {
            return $this->success(InvoiceResource::make($this->invoices->issue(Arr::requiredParam($request, 'id'), $this->key($request))), 'Invoice issued');
        } catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function createCreditMemo(CreateCreditMemoRequest $request, Request $rawRequest): Response
    {
        $data = $request->validated();
        try {
            return $this->success($this->memos->create(Arr::requiredParam($rawRequest, 'id'), Arr::requiredInt($data, 'amount_minor'), is_string($data['reason'] ?? null) ? $data['reason'] : '', $this->key($rawRequest)), 'Credit memo issued');
        } catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function showCreditMemo(Request $request): Response
    {
        try {
            $memo = $this->memos->find(Arr::requiredParam($request, 'id'));
            return $memo === null ? $this->error('Credit memo not found', 404) : $this->success(CreditMemoResource::make($memo), 'Credit memo');
        } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
    }

    private function key(Request $request): string
    {
        $value = $request->header('idempotency-key', '');
        if (!is_string($value) || $value === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        return $value;
    }

}
