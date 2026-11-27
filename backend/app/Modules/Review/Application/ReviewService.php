<?php

declare(strict_types=1);

namespace App\Modules\Review\Application;

use App\Models\MediaAsset;
use App\Modules\Review\Contracts\ReviewStore;
use App\Support\Arr;
use Siro\Core\Event;
use Siro\Core\Logger;

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
        $images = $this->validateImages($data);
        /** @var array<string, mixed> $result */
        $result = $this->store->create(['product_id' => $productId, 'variant_id' => Arr::requiredStr($line, 'variant_id'), 'customer_id' => $customerId, 'order_id' => $orderId, 'order_line_id' => $lineId, 'rating' => $rating, 'title' => Arr::str($data, 'title'), 'body' => Arr::requiredStr($data, 'body'), 'verified_purchase' => 1, 'purchase_evidence_json' => json_encode(['order_id' => $orderId, 'order_line_id' => $lineId, 'order_state' => Arr::str($line, 'order_state')], JSON_THROW_ON_ERROR), 'images_json' => $images === [] ? null : json_encode($images, JSON_THROW_ON_ERROR)]);
        $this->emit('review.submitted', ['review_id' => Arr::str($result, 'id'), 'product_id' => $productId, 'customer_id' => $customerId, 'rating' => $rating, 'at' => date('Y-m-d H:i:s')]);
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
        $review = $this->store->moderate($id, $status, $note);
        if ($review !== null && $status === 'approved') {
            $this->emit('review.approved', ['review_id' => $id, 'product_id' => Arr::str($review, 'product_id'), 'at' => date('Y-m-d H:i:s')]);
        }
        return $review;
    }

    /**
     * Batch 1 Platform §2.7: images must be URLs already uploaded via the
     * media library (never raw file uploads at the review endpoint), max 5
     * per review. The stored form is `/storage/...`; the public (CDN) form
     * is accepted too and matched on its path.
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function validateImages(array $data): array
    {
        $images = $data['images'] ?? null;
        if ($images === null) return [];
        if (!is_array($images)) throw new \InvalidArgumentException('Review images must be an array of uploaded media URLs');
        if (count($images) > 5) throw new \InvalidArgumentException('A review can have at most 5 images');
        $clean = [];
        foreach ($images as $image) {
            if (!is_string($image) || trim($image) === '') throw new \InvalidArgumentException('Review image URLs must be non-empty strings');
            $url = trim($image);
            if (!$this->isUploadedMediaUrl($url)) throw new \InvalidArgumentException('Review images must be uploaded media URLs');
            $clean[] = $url;
        }
        return $clean;
    }

    private function isUploadedMediaUrl(string $url): bool
    {
        $candidates = [$url];
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && $path !== '' && $path !== $url) $candidates[] = $path;
        return MediaAsset::query()->whereIn('url', $candidates)->first() !== null;
    }

    /**
     * A failing event listener must never break the review flow (RULE-E10 row 6).
     * @param array<string, mixed> $payload
     */
    private function emit(string $event, array $payload): void
    {
        try {
            Event::emit($event, $payload);
        } catch (\Throwable $e) {
            Logger::warning('Review event listener failed: ' . $event);
        }
    }
}
