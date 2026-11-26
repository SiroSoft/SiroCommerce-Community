<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Fulfillment\Contracts\FulfillmentService;
use App\Modules\Fulfillment\Contracts\ReturnService;
use App\Requests\Fulfillment\AllocateFulfillmentRequest;
use App\Requests\Fulfillment\CreateReturnLabelRequest;
use App\Requests\Fulfillment\InspectReturnRequest;
use App\Requests\Fulfillment\RejectReturnRequest;
use App\Requests\Fulfillment\RequestReturnRequest;
use App\Resources\ReturnResource;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class FulfillmentController extends Controller
{
    public function __construct(private readonly FulfillmentService $fulfillment, private readonly ReturnService $returns) {}

    public function allocate(AllocateFulfillmentRequest $request, Request $rawRequest): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        try {
            $lines = [];
            if (is_array($data['lines'] ?? null)) foreach ($data['lines'] as $line) {
                if (!is_array($line)) throw new \InvalidArgumentException('Fulfillment lines must be objects');
                $normalized = [];
                foreach ($line as $key => $value) if (is_string($key)) $normalized[$key] = $value;
                $lines[] = $normalized;
            }
            $result = $this->fulfillment->allocate(Arr::requiredStr($data, 'order_id'), $lines, $this->key($rawRequest));
            return $this->success($result, 'Fulfillment allocated');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function ship(Request $request): Response
    {
        try {
            $body = $request->body();
            $lines = null;
            if (is_array($body['lines'] ?? null)) {
                $lines = [];
                foreach ($body['lines'] as $line) { if (!is_array($line)) throw new \InvalidArgumentException('Package lines must be objects'); $lines[] = array_filter($line, 'is_string', ARRAY_FILTER_USE_KEY); }
            }
            return $this->success($this->fulfillment->ship(Arr::requiredParam($request, 'id'), $this->key($request), $lines), 'Fulfillment shipped');
        }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function deliver(Request $request): Response
    {
        try { return $this->success($this->fulfillment->deliver(Arr::requiredParam($request, 'id'), $this->key($request)), 'Fulfillment delivered'); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function markReady(Request $request): Response
    {
        try {
            $body = $request->body();
            $point = is_array($body) && is_string($body['pickup_point_code'] ?? null) ? $body['pickup_point_code'] : null;
            return $this->success($this->fulfillment->markReady(Arr::requiredParam($request, 'id'), $this->key($request), $point), 'Fulfillment ready for pickup');
        }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function collect(Request $request): Response
    {
        try { return $this->success($this->fulfillment->collect(Arr::requiredParam($request, 'id'), $this->key($request)), 'Pickup collected'); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function cancel(Request $request): Response
    {
        try { return $this->success($this->fulfillment->cancel(Arr::requiredParam($request, 'id'), $this->key($request)), 'Fulfillment cancelled'); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function track(Request $request): Response
    {
        try { return $this->success($this->fulfillment->track(Arr::requiredParam($request, 'id')), 'Fulfillment tracked'); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function requestReturn(RequestReturnRequest $request, Request $rawRequest): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        try {
            $lines = [];
            foreach (is_array($data['lines'] ?? null) ? $data['lines'] : [] as $line) {
                if (!is_array($line)) throw new \InvalidArgumentException('Return lines must be objects');
                $lines[] = array_filter($line, 'is_string', ARRAY_FILTER_USE_KEY);
            }
            return $this->success(ReturnResource::make($this->returns->request(Arr::requiredStr($data, 'order_id'), Arr::requiredStr($data, 'fulfillment_id'), $lines, is_string($data['reason'] ?? null) ? $data['reason'] : '', $this->key($rawRequest))), 'Return requested');
        } catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function completeReturn(Request $request): Response
    {
        try { return $this->success(ReturnResource::make($this->returns->complete(Arr::requiredParam($request, 'id'), $this->key($request))), 'Return completed'); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function approveReturn(Request $request): Response { return $this->returnTransition($request, 'approve'); }
    public function inspectReturn(InspectReturnRequest $request, Request $rawRequest): Response { $d=$request->validated(); try{return $this->success(ReturnResource::make($this->returns->inspect(Arr::requiredParam($rawRequest,'id'),$this->key($rawRequest),Arr::requiredStr($d,'disposition'),is_string($d['note']??null)?$d['note']:'')),'Return inspected');}catch(\InvalidArgumentException|\RuntimeException $e){return $this->error($e->getMessage(),422);} }
    public function approveRefundReturn(Request $request): Response { return $this->returnTransition($request, 'approveRefund'); }
    public function rejectReturn(RejectReturnRequest $request, Request $rawRequest): Response { $d=$request->validated(); try{return $this->success(ReturnResource::make($this->returns->reject(Arr::requiredParam($rawRequest,'id'),$this->key($rawRequest),is_string($d['note']??null)?$d['note']:'')),'Return rejected');}catch(\InvalidArgumentException|\RuntimeException $e){return $this->error($e->getMessage(),422);} }
    public function createReturnLabel(CreateReturnLabelRequest $request, Request $rawRequest): Response { $d=$request->validated(); try{$shipping=[]; foreach(is_array($d['shipping']??null)?$d['shipping']:[] as $key=>$value)if(is_string($key))$shipping[$key]=$value; return $this->success(ReturnResource::make($this->returns->createLabel(Arr::requiredParam($rawRequest,'id'),$shipping,$this->key($rawRequest))),'Return label created');}catch(\InvalidArgumentException|\RuntimeException $e){return $this->error($e->getMessage(),422);} }

    private function returnTransition(Request $request, string $operation): Response { try { $id=Arr::requiredParam($request,'id'); $key=$this->key($request); $result=$operation==='approve'?$this->returns->approve($id,$key):$this->returns->approveRefund($id,$key); return $this->success(ReturnResource::make($result),'Return updated'); } catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(),422); } }

    private function key(Request $request): string
    {
        $value = $request->header('idempotency-key', '');
        return is_string($value) ? $value : '';
    }
}
