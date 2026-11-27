<?php

declare(strict_types=1);

/**
 * Mounted by ExtensionRouteLoader under /api/ext/siro/product-photo-reviews/.
 * Rows: [method, relative path, [controller, action], middleware tokens].
 * Public gallery is anonymous; the loader still enforces Idempotency-Key
 * on POST/PUT. Admin routes require the manifest permission. Every query is
 * filtered by CommerceScope (store_id + channel_id).
 */

use App\Extensions\Siro\ProductPhotoReviews\PhotoReviewsController;

require_once __DIR__ . '/src/PhotoReviewsService.php';
require_once __DIR__ . '/src/PhotoReviewsController.php';

return [
    ['GET', 'public', [PhotoReviewsController::class, 'publicGallery'], ['throttle:120,1']],
    ['GET', 'admin/overview', [PhotoReviewsController::class, 'adminOverview'], ['cap:photo-reviews:moderate', 'throttle:60,1']],
    ['GET', 'admin', [PhotoReviewsController::class, 'adminList'], ['cap:photo-reviews:moderate', 'throttle:60,1']],
    ['PUT', 'admin/media/{id}', [PhotoReviewsController::class, 'moderateMedia'], ['cap:photo-reviews:moderate', 'throttle:20,1']],
    ['PUT', 'admin/reviews/{id}', [PhotoReviewsController::class, 'moderateReview'], ['cap:photo-reviews:moderate', 'throttle:20,1']],
];
