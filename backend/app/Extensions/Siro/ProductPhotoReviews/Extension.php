<?php

declare(strict_types=1);

namespace App\Extensions\Siro\ProductPhotoReviews;

use App\Contracts\ExtensionInterface;

require_once __DIR__ . '/src/PhotoReviewsService.php';
require_once __DIR__ . '/src/PhotoReviewsController.php';

/**
 * Free product photo-reviews extension: approved review photos render in
 * the PDP gallery slot below the product gallery, and admins moderate
 * individual photos (approve/reject) plus the review itself. Rows live in
 * ext_product_review_media and are scoped by store_id + channel_id on every
 * read and write. The photos themselves live on core
 * product_reviews.images_json (Wave 0 §2.7).
 */
final class Extension implements ExtensionInterface
{
    public const EXTENSION_ID = 'siro/product-photo-reviews';
    public const VERSION = '1.0.0';

    /** @var array<string, int> */
    private const CONFIG = [
        'max_images_per_review' => 5,
        'max_gallery_items' => 24,
    ];

    public static function id(): string
    {
        return self::EXTENSION_ID;
    }

    public static function compatibleCore(): string
    {
        return '^1.0';
    }

    public static function compatibleCommerce(): string
    {
        return '^0.3';
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'id' => self::id(),
            'version' => self::VERSION,
            'requires' => ['core' => self::compatibleCore(), 'commerce' => self::compatibleCommerce()],
            'capabilities' => ['photo-reviews:read', 'photo-reviews:moderate'],
            'permissions' => ['photo-reviews:moderate'],
            'migrations' => ['migrations/20261126000031_create_ext_product_review_media.php'],
            'routes' => ['routes.php'],
            'config' => self::CONFIG,
            'slots' => [
                'admin' => [
                    'label' => 'Product Photo Reviews',
                    'section' => 'Commerce',
                ],
                'storefront' => ['pdp.below-gallery'],
            ],
            'admin' => [
                'label' => 'Product Photo Reviews',
                'section' => 'Commerce',
                'panels' => [
                    ['key' => 'overview', 'title' => 'Photo overview', 'type' => 'stats', 'endpoint' => 'admin/overview', 'permission' => 'photo-reviews:moderate'],
                    ['key' => 'moderation', 'title' => 'Photo moderation queue', 'type' => 'table', 'endpoint' => 'admin', 'permission' => 'photo-reviews:moderate'],
                ],
            ],
        ];
    }

    /** @return array<string, int> */
    public function config(): array
    {
        return self::CONFIG;
    }

    /** @return array<string, \App\Contracts\PaymentGatewayInterface> */
    public function paymentGateways(): array
    {
        return [];
    }

    /** @return array<string, \App\Contracts\ShippingProviderInterface> */
    public function shippingProviders(): array
    {
        return [];
    }

    public function boot(): void
    {
        // No raw Event::on listeners on purpose. Core product_reviews carries
        // no store/channel, so review.submitted / review.approved payloads
        // carry no scope — a listener could not create scoped media rows
        // without an unscoped WHERE review_id = ? query (EXTENSION_SCOPING
        // §7 stop sign). The events are used through their effects instead:
        // ReviewService::moderate() emits review.approved, and the gallery
        // reads ReviewService::published(), which only returns approved
        // reviews. Media rows are ensured from the request scope of the
        // gallery/admin call that first observes each photo.
    }

    public function shutdown(): void
    {
    }
}
