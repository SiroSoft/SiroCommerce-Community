<?php
declare(strict_types=1);
namespace App\Modules\Analytics\Domain;

final class AnalyticsEventSchema
{
    public const VERSION = 1;

    /** @var string[] */
    private const EVENTS = [
        'product.view', 'cart.add', 'checkout.start', 'payment.done', 'order.placed',
        'order.transitioned', 'order.refunded', 'payment.captured', 'payment.failed',
        'refund.created', 'refund.succeeded', 'subscription.started', 'subscription.paused',
        'subscription.resumed', 'subscription.cancelled', 'subscription.renewed', 'subscription.payment_failed',
        'b2b.order.submitted', 'b2b.order.approved', 'b2b.order.rejected', 'promotion.applied',
        'promotion.rejected', 'fulfillment.allocated', 'fulfillment.shipped', 'fulfillment.delivered',
        'fulfillment.cancelled', 'fulfillment.failed', 'fulfillment.ready_for_pickup', 'fulfillment.picked_up',
    ];

    /** @var string[] */
    private const STOREFRONT_EVENTS = ['product_view', 'wishlist_add', 'wishlist_remove', 'search_submit', 'search_click', 'checkout_started', 'recommendation_impression', 'recommendation_click', 'experiment_exposure', 'web_vitals'];

    /** @var array<string, string[]> */
    private const STOREFRONT_PROPERTIES = [
        'product_view' => ['product_id', 'category'],
        'wishlist_add' => ['product_id', 'category'],
        'wishlist_remove' => ['product_id', 'category'],
        'search_submit' => ['query_length', 'query'],
        'search_click' => ['query', 'product_id', 'position'],
        'checkout_started' => ['cart_id'],
        'recommendation_impression' => ['recommendation_id', 'product_id', 'position', 'source'],
        'recommendation_click' => ['recommendation_id', 'product_id', 'position', 'source'],
        'experiment_exposure' => ['experiment_id', 'variant_id'],
        'web_vitals' => ['metric', 'value_ms', 'rating', 'page'],
    ];

    public static function accepts(string $event, int $version = self::VERSION): bool
    {
        return $version === self::VERSION && (in_array($event, self::EVENTS, true) || self::acceptsStorefront($event));
    }

    /** @return string[] */
    public static function events(): array { return array_merge(self::EVENTS, self::STOREFRONT_EVENTS); }

    public static function acceptsStorefront(string $event): bool
    {
        return in_array($event, self::STOREFRONT_EVENTS, true);
    }

    /** @return array<string, mixed> */
    public static function validateStorefrontPayload(string $event, mixed $properties): array
    {
        if (!self::acceptsStorefront($event) || !is_array($properties) || count($properties) > 8) {
            throw new \InvalidArgumentException('Invalid analytics properties');
        }
        $allowed = self::STOREFRONT_PROPERTIES[$event];
        $out = [];
        foreach ($properties as $key => $value) {
            if (!is_string($key) || !in_array($key, $allowed, true)) throw new \InvalidArgumentException('Unsupported analytics property');
            if (is_string($value)) {
                if ($value === '' || strlen($value) > 128 || preg_match('/[\r\n]/', $value) === 1) throw new \InvalidArgumentException('Invalid analytics property');
                $out[$key] = $value;
            } elseif (is_int($value) && in_array($key, ['query_length', 'position'], true) && $value >= 0 && $value <= 200) {
                $out[$key] = $value;
            } elseif (is_int($value) && $key === 'value_ms' && $value >= 0 && $value <= 60000) {
                $out[$key] = $value;
            } else {
                throw new \InvalidArgumentException('Invalid analytics property');
            }
        }
        if ($event === 'web_vitals') {
            if (!in_array($out['metric'] ?? null, ['lcp', 'inp', 'cls'], true)) throw new \InvalidArgumentException('metric must be lcp, inp or cls');
            if (!in_array($out['rating'] ?? null, ['good', 'needs-improvement', 'poor'], true)) throw new \InvalidArgumentException('rating is required');
            if (!isset($out['value_ms'], $out['page'])) throw new \InvalidArgumentException('value_ms and page are required');
        }
        if (in_array($event, ['product_view', 'wishlist_add', 'wishlist_remove', 'recommendation_impression', 'recommendation_click'], true) && !isset($out['product_id'])) {
            throw new \InvalidArgumentException('product_id is required for this event');
        }
        if (in_array($event, ['recommendation_impression', 'recommendation_click'], true) && (!isset($out['recommendation_id']) || !isset($out['position']) || !isset($out['source']))) throw new \InvalidArgumentException('Recommendation context is required');
        if ($event === 'search_submit' && !isset($out['query_length'])) throw new \InvalidArgumentException('query_length is required for this event');
        if (isset($out['query'])) {
            $normalized = \App\Modules\Smart\Application\SmartQuery::normalize($out['query']);
            if ($normalized === null) throw new \InvalidArgumentException('query is invalid');
            $out['query'] = $normalized;
        }
        if ($event === 'search_click' && (!isset($out['query'], $out['position']))) throw new \InvalidArgumentException('query and position are required');
        return $out;
    }
}
