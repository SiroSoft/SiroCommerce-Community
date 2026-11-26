<?php

declare(strict_types=1);

namespace App\Modules\Review\Application;

use App\Modules\Review\Contracts\ReviewStore;
use App\Support\Arr;

final class ReviewService
{
    public function __construct(private readonly ReviewStore $store) {}
    /** @return array<int, array<string, mixed>> */
    public function published(string $productId): array
    {
        return $this->store->published($productId);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function submit(string $customerId, string $productId, array $data): array
    {
        $orderId = Arr::requiredStr($data, 'order_id');
        $lineId = Arr::requiredStr($data, 'order_line_id');
        $line = $this->store->ownedOrderLine($customerId, $orderId, $lineId);
        if ($line === null || Arr::str($line, 'product_id') !== $productId) throw new \RuntimeException('Review requires an order line owned by the customer');
        if (!in_array(Arr::str($line, 'order_state'), ['delivered', 'completed'], true)) throw new \RuntimeException('Reviews require a delivered order');
        if ($this->store->alreadySubmitted($customerId, $lineId)) throw new \RuntimeException('This order line has already been reviewed');
        $rating = Arr::requiredInt($data, 'rating');
        if ($rating < 1 || $rating > 5) throw new \InvalidArgumentException('Rating must be between 1 and 5');
        /** @var array<string, mixed> $result */
        $result = $this->store->create(['product_id' => $productId, 'variant_id' => Arr::requiredStr($line, 'variant_id'), 'customer_id' => $customerId, 'order_id' => $orderId, 'order_line_id' => $lineId, 'rating' => $rating, 'title' => Arr::str($data, 'title'), 'body' => Arr::requiredStr($data, 'body'), 'verified_purchase' => 1, 'purchase_evidence_json' => json_encode(['order_id' => $orderId, 'order_line_id' => $lineId, 'order_state' => Arr::str($line, 'order_state')], JSON_THROW_ON_ERROR)]);
        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->store->all();
    }

    /** @return array<string, mixed>|null */
    public function moderate(string $id, string $status, string $note): ?array
    {
        if (!in_array($status, ['approved', 'rejected', 'pending'], true)) throw new \InvalidArgumentException('Invalid review status');
        return $this->store->moderate($id, $status, $note);
    }
}
