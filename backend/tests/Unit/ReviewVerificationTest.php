<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Review\Application\ReviewService;
use App\Modules\Review\Contracts\ReviewStore;
use Tests\TestCase;

final class ReviewVerificationTest extends TestCase
{
    public function testReviewRequiresDeliveredPurchaseAndStoresEvidence(): void
    {
        $store = new ReviewStoreFixture(['order_state' => 'pending']);
        $service = new ReviewService($store);

        $this->expectException(\RuntimeException::class);
        $service->submit('customer-1', 'product-1', ['order_id' => 'order-1', 'order_line_id' => 'line-1', 'rating' => 5, 'body' => 'Not yet']);
    }

    public function testDeliveredReviewIsMarkedVerified(): void
    {
        $store = new ReviewStoreFixture(['order_state' => 'delivered']);
        $review = (new ReviewService($store))->submit('customer-1', 'product-1', ['order_id' => 'order-1', 'order_line_id' => 'line-1', 'rating' => 5, 'body' => 'Good']);

        self::assertSame(1, $store->created['verified_purchase']);
        self::assertStringContainsString('order-1', (string) $store->created['purchase_evidence_json']);
        self::assertSame(1, $review['verified_purchase']);
    }
}

/** @implements ReviewStore */
final class ReviewStoreFixture implements ReviewStore
{
    /** @param array<string, mixed> $line */
    public function __construct(private array $line) {}

    /** @var array<string, mixed> */
    public array $created = [];

    public function published(string $productId): array { return []; }
    public function ownedOrderLine(string $customerId, string $orderId, string $lineId): ?array { return ['variant_id' => 'variant-1', 'product_id' => 'product-1'] + $this->line; }
    public function alreadySubmitted(string $customerId, string $lineId): bool { return false; }
    public function create(array $data): array { $this->created = $data; return ['id' => 'review-1'] + $data; }
    public function all(): array { return []; }
    public function moderate(string $id, string $status, string $note): ?array { return null; }
}
