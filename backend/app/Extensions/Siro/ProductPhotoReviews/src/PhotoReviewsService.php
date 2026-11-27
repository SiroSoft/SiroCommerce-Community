<?php

declare(strict_types=1);

namespace App\Extensions\Siro\ProductPhotoReviews;

use App\Modules\Extension\Application\ExtensionScope;
use App\Modules\Review\Application\ReviewService;
use App\Support\CommerceScope;
use App\Support\Ids;
use Siro\Core\Container;
use Siro\Core\Database;

/**
 * Per-photo moderation for product reviews.
 *
 * The canonical photos live on core `product_reviews.images_json` (Wave 0,
 * §2.7 — max 5 already-uploaded media URLs per review). This extension owns
 * one `ext_product_review_media` row per photo: the row is the moderation
 * record (pending | approved | rejected) for that photo in one store/channel
 * scope. The review itself is moderated through core
 * `ReviewService::moderate()`; a photo only renders on the PDP when its
 * review is approved (core `ReviewService::published()`) AND the photo row
 * is not rejected.
 *
 * Scope note: core `product_reviews` carries no store/channel, so the
 * `review.submitted` / `review.approved` events carry no scope either. Media
 * rows are therefore created ("ensured") from the request scope of the
 * gallery/admin call that first observes the photo — never from a raw event
 * listener, which could not attribute a scope without writing an unscoped
 * `WHERE review_id = ?` query (EXTENSION_SCOPING §7 stop sign). Visibility
 * still derives from the events' effects: `moderate()` emits
 * `review.approved`, and `published()` only returns approved reviews.
 */
final class PhotoReviewsService
{
    /** @var list<string> */
    public const STATUSES = ['pending', 'approved', 'rejected'];

    public const MAX_PRODUCT_ID = 64;
    public const MAX_REVIEW_ID = 64;
    public const MAX_URL = 2048;
    public const MAX_NOTE = 500;
    public const MAX_IMAGES_PER_REVIEW = 5;
    public const MAX_LIST = 100;
    public const MAX_GALLERY = 24;

    private const COLUMNS = 'id, store_id, channel_id, review_id, product_id, url, sort, status, moderation_note, created_at, updated_at';

    private ?ReviewService $reviews = null;

    public function __construct(?ReviewService $reviews = null)
    {
        $this->reviews = $reviews;
    }

    private function reviews(): ReviewService
    {
        if ($this->reviews === null) {
            $made = Container::getInstance()->make(ReviewService::class);
            if (!$made instanceof ReviewService) {
                throw new \RuntimeException('ReviewService could not be resolved from the container');
            }
            $this->reviews = $made;
        }
        return $this->reviews;
    }

    /**
     * Approved photos for one product, newest review first.
     *
     * @return list<array<string, mixed>>
     */
    public function listGallery(CommerceScope $scope, string $productId, int $limit = self::MAX_GALLERY): array
    {
        $productId = $this->cleanProductId($productId);
        $limit = max(1, min(self::MAX_GALLERY, $limit));
        $items = [];
        foreach ($this->reviews()->published($productId) as $review) {
            $reviewId = $this->stringValue($review['id'] ?? null);
            if ($reviewId === '') {
                continue;
            }
            $rows = $this->ensureRows($scope, $review, 'approved');
            $bySort = [];
            foreach ($rows as $row) {
                $bySort[$this->intValue($row['sort'] ?? null)] = $row;
            }
            foreach ($this->reviewImages($review) as $sort => $url) {
                $row = $bySort[$sort] ?? null;
                if ($row !== null && ($row['status'] ?? null) === 'rejected') {
                    continue;
                }
                $items[] = $this->galleryItem($row, $review, $url, $sort);
                if (count($items) >= $limit) {
                    return $items;
                }
            }
        }
        return $items;
    }

    /**
     * Moderation queue: every photo of every review (any review status),
     * joined with its review, filtered by photo status.
     *
     * @return list<array<string, mixed>>
     */
    public function adminQueue(CommerceScope $scope, string $state = 'pending', ?string $productId = null): array
    {
        $state = strtolower(trim($state));
        if ($state === '') {
            $state = 'pending';
        }
        if ($state !== 'all' && !in_array($state, self::STATUSES, true)) {
            throw new \InvalidArgumentException('state must be pending, approved, rejected or all');
        }
        $productId = $productId !== null && trim($productId) !== '' ? $this->cleanProductId($productId) : null;

        $items = [];
        foreach ($this->reviews()->all() as $review) {
            $reviewId = $this->stringValue($review['id'] ?? null);
            $reviewProductId = $this->stringValue($review['product_id'] ?? null);
            if ($reviewId === '' || $reviewProductId === '') {
                continue;
            }
            if ($productId !== null && $reviewProductId !== $productId) {
                continue;
            }
            $default = ($review['status'] ?? null) === 'approved' ? 'approved' : 'pending';
            foreach ($this->ensureRows($scope, $review, $default) as $row) {
                if ($state !== 'all' && ($row['status'] ?? null) !== $state) {
                    continue;
                }
                $items[] = $this->queueItem($row, $review);
            }
        }
        // Newest photos first; photos of the same review stay in gallery
        // (sort) order. The final id tie-break is deterministic per stored
        // data — ids are random hex (Ids::new), so id must never decide the
        // order between two photos of one review (created_at is only
        // second-precision, so same-second rows would otherwise flip randomly).
        usort($items, fn (array $a, array $b): int => strcmp($this->stringValue($b['created_at'] ?? null), $this->stringValue($a['created_at'] ?? null)) ?: ($this->intValue($a['sort'] ?? null) <=> $this->intValue($b['sort'] ?? null)) ?: strcmp($this->stringValue($a['id'] ?? null), $this->stringValue($b['id'] ?? null)));
        return array_slice($items, 0, self::MAX_LIST);
    }

    /**
     * Admin overview: counts per photo status + recent queue rows.
     *
     * @return array<string, mixed>
     */
    public function adminOverview(CommerceScope $scope): array
    {
        // Ensure rows first: the counts below must include photos that no
        // admin call has observed yet (e.g. a review submitted minutes ago).
        $queue = $this->adminQueue($scope, 'all');
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $counts = $this->rows(
            'SELECT status, COUNT(*) AS n FROM ext_product_review_media WHERE ' . $where . ' GROUP BY status',
            $params
        );
        $byStatus = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ($counts as $count) {
            $status = $this->stringValue($count['status'] ?? null);
            if (array_key_exists($status, $byStatus)) {
                $byStatus[$status] = $this->intValue($count['n'] ?? null);
            }
        }
        $products = $this->rows(
            'SELECT COUNT(DISTINCT product_id) AS n FROM ext_product_review_media WHERE ' . $where,
            $params
        );
        $recent = array_slice($queue, 0, 10);
        return [
            'stats' => [
                ['label' => 'Photos pending', 'value' => (string) $byStatus['pending']],
                ['label' => 'Photos approved', 'value' => (string) $byStatus['approved']],
                ['label' => 'Photos rejected', 'value' => (string) $byStatus['rejected']],
                ['label' => 'Products with photos', 'value' => (string) $this->intValue($products[0]['n'] ?? null)],
            ],
            'table' => [
                'columns' => [
                    ['key' => 'product_id', 'label' => 'Product'],
                    ['key' => 'review_id', 'label' => 'Review'],
                    ['key' => 'url', 'label' => 'Photo'],
                    ['key' => 'status', 'label' => 'Status'],
                ],
                'rows' => array_map(static function (array $row): array {
                    $out = [];
                    foreach (['product_id', 'review_id', 'url', 'status'] as $key) {
                        $out[$key] = is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';
                    }
                    return $out;
                }, $recent),
            ],
        ];
    }

    /**
     * Approve or reject one photo. Repeated identical updates are idempotent.
     *
     * @return array<string, mixed>|null
     */
    public function moderateMedia(CommerceScope $scope, string $id, string $status, ?string $note = null): ?array
    {
        $id = trim($id);
        if ($id === '') {
            throw new \InvalidArgumentException('Photo id is required');
        }
        $status = strtolower(trim($status));
        if (!in_array($status, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException('status must be approved or rejected');
        }
        $note = $this->cleanNote($note);

        $existing = $this->find($scope, $id);
        if ($existing === null) {
            return null;
        }
        if (($existing['status'] ?? null) === $status && ($existing['moderation_note'] ?? '') === $note) {
            return $existing;
        }

        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $stmt = Database::connection()->prepare(
            'UPDATE ext_product_review_media SET status = ?, moderation_note = ?, updated_at = ? WHERE ' . $where . ' AND id = ?'
        );
        if (!$stmt instanceof \PDOStatement) {
            throw new \RuntimeException('Photo could not be moderated');
        }
        $stmt->execute([$status, $note, date('Y-m-d H:i:s'), ...$params, $id]);
        $updated = $this->find($scope, $id);
        if ($updated === null) {
            throw new \RuntimeException('Photo could not be moderated');
        }
        return $updated;
    }

    /**
     * Moderate the review itself through core ReviewService (emits
     * `review.approved` when the status becomes approved).
     *
     * @return array<string, mixed>|null
     */
    public function moderateReview(string $id, string $status, ?string $note = null): ?array
    {
        $id = trim($id);
        if ($id === '') {
            throw new \InvalidArgumentException('Review id is required');
        }
        $status = strtolower(trim($status));
        if (!in_array($status, ['approved', 'rejected', 'pending'], true)) {
            throw new \InvalidArgumentException('status must be approved, rejected or pending');
        }
        return $this->reviews()->moderate($id, $status, $this->cleanNote($note));
    }

    /**
     * Create missing media rows for one review's photos in the request scope.
     * Existing rows (e.g. a photo an admin already rejected) are never
     * overwritten. Returns the review's rows ordered by sort.
     *
     * @param array<string, mixed> $review
     * @return list<array<string, mixed>>
     */
    private function ensureRows(CommerceScope $scope, array $review, string $defaultStatus): array
    {
        $reviewId = $this->stringValue($review['id'] ?? null);
        $productId = $this->stringValue($review['product_id'] ?? null);
        if ($reviewId === '' || $productId === '') {
            return [];
        }
        $now = date('Y-m-d H:i:s');
        foreach ($this->reviewImages($review) as $sort => $url) {
            $existing = $this->findByReviewSort($scope, $reviewId, $sort);
            if ($existing !== null) {
                continue;
            }
            $row = ExtensionScope::merge([
                'id' => Ids::new(),
                'review_id' => $reviewId,
                'product_id' => $productId,
                'url' => $url,
                'sort' => $sort,
                'status' => $defaultStatus,
                'moderation_note' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ], $scope);
            try {
                Database::table('ext_product_review_media')->insert($row);
            } catch (\Throwable) {
                // Lost insert race with a concurrent ensure: the winner's row
                // is the canonical one; a duplicate is never created thanks
                // to the scoped unique key on (review_id, sort).
                continue;
            }
        }
        if ($defaultStatus === 'approved') {
            // Review approval approves its photos: flip rows that are still
            // pending (e.g. created while the review awaited moderation).
            // Explicitly rejected photos stay rejected.
            [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
            $stmt = Database::connection()->prepare(
                'UPDATE ext_product_review_media SET status = ?, updated_at = ? WHERE ' . $where . ' AND review_id = ? AND status = ?'
            );
            if ($stmt instanceof \PDOStatement) {
                $stmt->execute(['approved', $now, ...$params, $reviewId, 'pending']);
            }
        }
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        return $this->rows(
            'SELECT ' . self::COLUMNS . ' FROM ext_product_review_media WHERE ' . $where
            . ' AND review_id = ? ORDER BY sort ASC, id ASC',
            array_merge($params, [$reviewId])
        );
    }

    /**
     * Decode a review's images_json into [sort => url], capped at 5.
     *
     * @param array<string, mixed> $review
     * @return array<int, string>
     */
    private function reviewImages(array $review): array
    {
        $raw = $review['images_json'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $images = [];
        foreach (array_values($decoded) as $sort => $url) {
            if (!is_string($url) || trim($url) === '') {
                continue;
            }
            $url = trim($url);
            if (mb_strlen($url) > self::MAX_URL) {
                continue;
            }
            $images[$sort] = $url;
            if (count($images) >= self::MAX_IMAGES_PER_REVIEW) {
                break;
            }
        }
        return $images;
    }

    /** @return array<string, mixed>|null */
    private function find(CommerceScope $scope, string $id): ?array
    {
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        return $this->first(
            'SELECT ' . self::COLUMNS . ' FROM ext_product_review_media WHERE ' . $where . ' AND id = ? LIMIT 1',
            array_merge($params, [$id])
        );
    }

    /** @return array<string, mixed>|null */
    private function findByReviewSort(CommerceScope $scope, string $reviewId, int $sort): ?array
    {
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        return $this->first(
            'SELECT ' . self::COLUMNS . ' FROM ext_product_review_media WHERE ' . $where . ' AND review_id = ? AND sort = ? LIMIT 1',
            array_merge($params, [$reviewId, $sort])
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $review
     * @return array<string, mixed>
     */
    private function galleryItem(?array $row, array $review, string $url, int $sort): array
    {
        return [
            'id' => $row !== null ? $this->stringValue($row['id'] ?? null) : '',
            'review_id' => $this->stringValue($review['id'] ?? null),
            'product_id' => $this->stringValue($review['product_id'] ?? null),
            'url' => $url,
            'sort' => $sort,
            'rating' => is_numeric($review['rating'] ?? null) ? (int) $review['rating'] : 0,
            'title' => $this->stringValue($review['title'] ?? null),
            'created_at' => $this->stringValue($review['created_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $review
     * @return array<string, mixed>
     */
    private function queueItem(array $row, array $review): array
    {
        return [
            'id' => $this->stringValue($row['id'] ?? null),
            'review_id' => $this->stringValue($row['review_id'] ?? null),
            'product_id' => $this->stringValue($row['product_id'] ?? null),
            'url' => $this->stringValue($row['url'] ?? null),
            'sort' => $this->intValue($row['sort'] ?? null),
            'status' => $this->stringValue($row['status'] ?? null),
            'moderation_note' => $this->stringValue($row['moderation_note'] ?? null),
            'review_status' => $this->stringValue($review['status'] ?? null),
            'review_rating' => is_numeric($review['rating'] ?? null) ? (int) $review['rating'] : 0,
            'review_title' => $this->stringValue($review['title'] ?? null),
            'created_at' => $this->stringValue($row['created_at'] ?? null),
        ];
    }

    private function cleanProductId(mixed $value): string
    {
        $productId = is_string($value) ? trim($value) : '';
        if ($productId === '') {
            throw new \InvalidArgumentException('product_id is required');
        }
        $this->assertPlainText($productId, 'product_id');
        if (mb_strlen($productId) > self::MAX_PRODUCT_ID) {
            throw new \InvalidArgumentException('product_id must be at most ' . self::MAX_PRODUCT_ID . ' characters');
        }
        return $productId;
    }

    private function cleanNote(mixed $value): string
    {
        $note = is_string($value) ? trim($value) : '';
        if ($note === '') {
            return '';
        }
        $this->assertPlainText($note, 'moderation_note');
        if (mb_strlen($note) > self::MAX_NOTE) {
            throw new \InvalidArgumentException('moderation_note must be at most ' . self::MAX_NOTE . ' characters');
        }
        return $note;
    }

    private function assertPlainText(string $value, string $field): void
    {
        if (preg_match('/[<>\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/u', $value) === 1) {
            throw new \InvalidArgumentException($field . ' must not contain HTML or control characters');
        }
        if (preg_match('/javascript\\s*:/i', $value) === 1) {
            throw new \InvalidArgumentException($field . ' must not contain scripts');
        }
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $stmt = Database::connection()->prepare($sql);
        if (!$stmt instanceof \PDOStatement) {
            return [];
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $clean = [];
                foreach ($row as $key => $value) {
                    if (is_string($key)) {
                        $clean[$key] = $value;
                    }
                }
                $result[] = $clean;
            }
        }
        return $result;
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    private function first(string $sql, array $params = []): ?array
    {
        $rows = $this->rows($sql, $params);
        return $rows[0] ?? null;
    }
}
