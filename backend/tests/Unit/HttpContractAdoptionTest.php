<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\FinanceController;
use App\Controllers\FulfillmentController;
use App\Requests\Finance\CreateInvoiceRequest;
use App\Requests\Fulfillment\RequestReturnRequest;
use App\Resources\InvoiceResource;
use App\Resources\OrderResource;
use App\Resources\ProductResource;
use App\Resources\ReturnResource;
use PHPUnit\Framework\TestCase;
use Siro\Core\FormRequest;
use Siro\Core\Request;
use Siro\Core\ValidationException;

final class HttpContractAdoptionTest extends TestCase
{
    public function testComplexMutationRequestsAreNativeFormRequests(): void
    {
        self::assertInstanceOf(FormRequest::class, new CreateInvoiceRequest(new Request('POST', '/')));
        $request = new Request('POST', '/', [], [], [
            'order_id' => 'order-1',
            'fulfillment_id' => 'fulfillment-1',
            'lines' => [['line_id' => 'line-1', 'qty' => 1]],
        ]);
        self::assertSame('order-1', (new RequestReturnRequest($request))->validated()['order_id']);

        $this->expectException(ValidationException::class);
        (new CreateInvoiceRequest(new Request('POST', '/', [], [], [])))->validate();
    }

    public function testPublicResourcesExcludeOperationalSecrets(): void
    {
        self::assertArrayNotHasKey('archived_at', ProductResource::make(['id' => 'p-1', 'name' => 'Book', 'archived_at' => 'secret']));
        self::assertArrayNotHasKey('idempotency_key', OrderResource::make(['id' => 'o-1', 'state' => 'pending', 'idempotency_key' => 'secret']));
        self::assertArrayNotHasKey('evidence_json', InvoiceResource::make(['id' => 'i-1', 'state' => 'draft', 'evidence_json' => 'secret']));
        self::assertArrayNotHasKey('label_last_error', ReturnResource::make(['id' => 'r-1', 'state' => 'requested', 'label_last_error' => 'secret']));
    }

    public function testComplexEndpointsUseAutomaticFormRequestResolution(): void
    {
        $financeParameter = (new \ReflectionMethod(FinanceController::class, 'createInvoice'))->getParameters()[0];
        $fulfillmentParameter = (new \ReflectionMethod(FulfillmentController::class, 'requestReturn'))->getParameters()[0];
        self::assertSame(CreateInvoiceRequest::class, $financeParameter->getType()?->getName());
        self::assertSame(RequestReturnRequest::class, $fulfillmentParameter->getType()?->getName());
    }
}
