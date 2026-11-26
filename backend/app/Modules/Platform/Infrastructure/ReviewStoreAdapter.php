<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Modules\Review\Contracts\ReviewStore;
use App\Support\Arr;
use App\Support\Ids;

final class ReviewStoreAdapter implements ReviewStore
{
    public function published(string $productId): array { return array_map(static fn ($row): array => Arr::row($row), ProductReview::query()->where('product_id', $productId)->where('status', 'approved')->orderBy('created_at', 'desc')->get()); }
    public function ownedOrderLine(string $customerId, string $orderId, string $lineId): ?array
    {
        $order = Order::query()->where('id', $orderId)->where('customer_id', $customerId)->first();
        $line = OrderLine::query()->where('id', $lineId)->where('order_id', $orderId)->first();
        if ($order === null || $line === null) return null;
        $lineData = Arr::row($line);
        $variantId = Arr::str($lineData, 'variant_id');
        $variant = ProductVariant::find($variantId);
        if ($variant === null) return null;
        return ['variant_id' => $variantId, 'product_id' => Arr::str($variant->toArray(), 'product_id'), 'order_state' => Arr::str($order->toArray(), 'state')];
    }
    public function alreadySubmitted(string $customerId, string $lineId): bool { return ProductReview::query()->where('customer_id', $customerId)->where('order_line_id', $lineId)->first() !== null; }
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(array $data): array { $id = Ids::new(); $row = ProductReview::create(['id' => $id, 'status' => 'pending', 'moderation_note' => '', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')] + $data)->toArray(); $row['id'] = $id; return $row; }
    public function all(): array { return array_map(static fn ($row): array => Arr::row($row), ProductReview::query()->orderBy('created_at', 'desc')->get()); }
    public function moderate(string $id, string $status, string $note): ?array { $review = ProductReview::find($id); if ($review === null) return null; $review->update(['status' => $status, 'moderation_note' => $note, 'updated_at' => date('Y-m-d H:i:s')]); return Arr::row($review); }
}
