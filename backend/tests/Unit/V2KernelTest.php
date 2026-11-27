<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Extension\Domain\ExtensionManifest;
use App\Modules\Payment\Application\PaymentOperationService;
use App\Modules\Payment\Contracts\PaymentOperationStore;
use App\Modules\Payment\Contracts\PaymentProvider;
use App\Modules\Payment\Domain\PaymentOperation;
use App\Modules\Pricing\Application\PricingEngine;
use App\Modules\Shipping\Contracts\RateProvider;
use App\Modules\Tax\Contracts\TaxProvider;
use App\Modules\Pricing\Domain\PricingSnapshot;
use App\Modules\Shared\Application\CommandContext;
use App\Modules\Shared\Domain\EventEnvelope;
use App\Modules\Shared\Domain\Money;
use App\Modules\Shared\Hooks\HookBus;
use App\Modules\Shared\Hooks\HookDecision;
use App\Modules\Shared\Hooks\HookHandler;
use PHPUnit\Framework\TestCase;

final class V2KernelTest extends TestCase
{
    public function testMoneyIsIntegerAndCurrencySafe(): void
    {
        $total = (new Money(100, 'VND'))->add(new Money(25, 'VND'))->subtract(new Money(5, 'VND'));
        self::assertSame(['amount_minor' => 120, 'currency' => 'VND'], $total->toArray());
        $this->expectException(\InvalidArgumentException::class);
        $total->add(new Money(1, 'USD'));
    }

    public function testHookOrderIsDeterministicAndPayloadIsMutableOnlyByHandlers(): void
    {
        $bus = new HookBus();
        $bus->register('pricing.before_quote', new class implements HookHandler {
            public function id(): string { return 'z-handler'; }
            public function priority(): int { return 20; }
            public function handle(string $hook, array &$payload): HookDecision { $payload['second'] = true; return HookDecision::continue(); }
        });
        $bus->register('pricing.before_quote', new class implements HookHandler {
            public function id(): string { return 'a-handler'; }
            public function priority(): int { return 10; }
            public function handle(string $hook, array &$payload): HookDecision { $payload['first'] = true; return HookDecision::continue(); }
        });
        $result = $bus->dispatch('pricing.before_quote', []);
        self::assertSame(['a-handler:continue', 'z-handler:continue'], $result['decisions']);
        self::assertSame(['first' => true, 'second' => true], $result['payload']);
    }

    public function testPricingSnapshotRejectsArithmeticMismatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PricingSnapshot(
            'snap_1', 'v2', [['sku' => 'SKU-1', 'qty' => 1]],
            new Money(1000, 'VND'), new Money(0, 'VND'), new Money(0, 'VND'),
            new Money(0, 'VND'), new Money(999, 'VND')
        );
    }

    public function testEventEnvelopeCarriesCommandContext(): void
    {
        $context = new CommandContext('system', 'cor_1', 'cmd_1', 'key_1');
        $event = new EventEnvelope('evt_1', 'catalog.product.saved.v1', '2026-01-01T00:00:00Z', $context, ['id' => 'p_1']);
        self::assertSame('catalog.product.saved.v1', $event->name());
        self::assertSame('cor_1', $event->toArray()['context']['correlation_id']);
    }

    public function testExtensionManifestDeclaresCapability(): void
    {
        $manifest = new ExtensionManifest('vendor/test', '1.0.0', ['commerce' => '^0.3'], ['hook.pricing.before_quote'], []);
        self::assertTrue($manifest->can('hook.pricing.before_quote'));
        self::assertFalse($manifest->can('payment.provider'));
    }

    public function testPaymentOperationIsCreatedOnceAndProviderResultIsStored(): void
    {
        $stored = [];
        $store = new class($stored) implements PaymentOperationStore {
            /** @param array<string, array<string, mixed>> $rows */
            public function __construct(private array &$rows) {}
            public function findByIdempotency(string $key): ?array { return $this->rows[$key] ?? null; }
            public function create(array $operation): void { $this->rows[$operation['idempotency_key']] = $operation; }
            public function complete(string $id, array $result): void { foreach ($this->rows as &$row) { if ($row['id'] === $id) { $row = array_merge($row, $result); } } }
        };
        $provider = new class implements PaymentProvider {
            public function execute(PaymentOperation $operation, array $context): array { return ['state' => 'authorized', 'provider_ref' => 'ref_1']; }
            public function verifyWebhook(string $rawBody, array $headers): bool { return true; }
        };
        $operation = new PaymentOperation('op_1', 'pay_1', 'authorize', new Money(100, 'VND'), 'pay-key-1');
        $service = new PaymentOperationService($store);
        self::assertTrue($service->execute($operation, $provider)->ok);
        self::assertSame(1, count($stored));
        self::assertSame('authorized', $stored['pay-key-1']['state']);
        self::assertSame('authorized', $service->execute($operation, $provider)->value['state']);
        self::assertSame(1, count($stored));
    }

    public function testProviderFailureIsPersistedAsUnknown(): void
    {
        $store = new class implements PaymentOperationStore {
            public array $result = [];
            public function findByIdempotency(string $key): ?array { return null; }
            public function create(array $operation): void {}
            public function complete(string $id, array $result): void { $this->result = $result; }
        };
        $provider = new class implements PaymentProvider {
            public function execute(PaymentOperation $operation, array $context): array { throw new \RuntimeException('timeout'); }
            public function verifyWebhook(string $rawBody, array $headers): bool { return false; }
        };
        $result = (new PaymentOperationService($store))->execute(
            new PaymentOperation('op_2', 'pay_2', 'capture', new Money(100, 'VND'), 'pay-key-2'),
            $provider
        );
        self::assertFalse($result->ok);
        self::assertSame('unknown', $store->result['state']);
    }

    public function testPricingEngineCreatesArithmeticSnapshot(): void
    {
        $tax = new class implements TaxProvider {
            public function calculate(array $context, array $lines): array { return ['total_minor' => 100, 'lines' => [], 'rules' => [['id' => 'tax-1']]]; }
        };
        $shipping = new class implements RateProvider {
            public function quote(array $context): array { return [['id' => 'standard', 'amount_minor' => 50]]; }
        };
        $snapshot = (new PricingEngine($tax, $shipping))->quote('snap_2', 'VND', [[
            'variant_id' => 'v1', 'sku' => 'SKU-1', 'name' => 'Item', 'qty' => 2, 'unit_price_minor' => 1000,
        ]]);
        self::assertSame(2150, $snapshot->grandTotal->amountMinor);
        self::assertSame('v2.0.0', $snapshot->engineVersion);
        self::assertSame(2000, $snapshot->toArray()['lines'][0]['line_total_minor']);
    }
}
