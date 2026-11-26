<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Auth\Application\AuthService;
use App\Modules\Cms\Application\CmsService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Modules\Platform\Infrastructure\CmsStoreAdapter;
use App\Modules\Platform\Infrastructure\WishlistStoreAdapter;
use App\Modules\Platform\Infrastructure\ReviewStoreAdapter;
use App\Modules\Review\Application\ReviewService;
use App\Modules\Wishlist\Application\WishlistService;
use App\Repositories\CartRepository;
use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;
use App\Support\Ids;
use Siro\Core\Request;
use Siro\Core\Router;
use App\Support\CommerceScope;
use Tests\TestCase;

final class CmsWishlistReviewTest extends TestCase
{
    public function testCmsDraftsAndPublishedBlocksAreSeparated(): void
    {
        $cms = new CmsService(new CmsStoreAdapter());
        $draft = $cms->create(['slug' => 'about', 'title' => 'About', 'status' => 'draft', 'locale' => 'en']);
        self::assertNull($cms->published('about'));
        $cms->update($draft['id'], ['status' => 'published', 'published_at' => date('Y-m-d H:i:s')]);
        $page = $cms->addBlock($draft['id'], ['type' => 'hero', 'content' => 'Visible', 'status' => 'published']);
        $cms->addBlock($draft['id'], ['type' => 'internal', 'content' => 'Hidden', 'status' => 'draft']);
        $published = $cms->published('about');
        self::assertNotNull($published);
        self::assertCount(1, $published['blocks']);
        self::assertSame($page['id'], $published['blocks'][0]['id']);
    }

    public function testPublishedCmsCacheIsInvalidatedWhenPageOrBlockChanges(): void
    {
        $cms = new CmsService(new CmsStoreAdapter());
        $slug = 'cached-page-' . substr(Ids::new(), 0, 8);
        $page = $cms->create(['slug' => $slug, 'title' => 'Original', 'status' => 'published']);
        self::assertSame('Original', $cms->published($slug)['title']);

        $cms->update($page['id'], ['title' => 'Updated']);
        self::assertSame('Updated', $cms->published($slug)['title']);

        $cms->addBlock($page['id'], ['type' => 'hero', 'content' => 'Visible', 'status' => 'published']);
        self::assertCount(1, $cms->published($slug)['blocks']);
    }

    public function testWishlistAddIsIdempotentAndRemovalIsCustomerScoped(): void
    {
        $wishlist = new WishlistService(new WishlistStoreAdapter());
        $first = $wishlist->add('100', ['product_id' => 'product-1', 'variant_id' => 'variant-1']);
        $second = $wishlist->add('100', ['product_id' => 'product-1', 'variant_id' => 'variant-2']);
        self::assertSame($first['id'], $second['id']);
        self::assertCount(1, $wishlist->list('100'));
        self::assertFalse($wishlist->remove('101', 'product-1'));
        self::assertTrue($wishlist->remove('100', 'product-1'));
        self::assertCount(0, $wishlist->list('100'));
    }

    public function testReviewsRequireOwnedOrderLineAndCanOnlyBeSubmittedOnce(): void
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $user = $auth->register(['name' => 'Buyer', 'email' => 'buyer-review@test', 'password' => 'secret123']);
        $customerId = (string) $user['id'];
        $variant = (new CartRepository())->createVariant(['id' => Ids::new(), 'product_id' => 'product-1', 'sku' => 'SKU-1', 'price_minor' => 100]);
        $orderId = Ids::new();
        $lineId = Ids::new();
        $orders = new OrderRepository();
        $orders->createOrder(['id' => $orderId, 'number' => 'REV-' . substr($orderId, 0, 8), 'customer_id' => $customerId, 'currency' => 'VND', 'subtotal_minor' => 100, 'discount_minor' => 0, 'shipping_minor' => 0, 'tax_minor' => 0, 'grand_total_minor' => 100, 'state' => 'delivered', 'idempotency_key' => 'review-' . $orderId]);
        $orders->addLine(['id' => $lineId, 'order_id' => $orderId, 'variant_id' => $variant['id'], 'sku' => 'SKU-1', 'name' => 'Product', 'qty' => 1, 'unit_price_minor' => 100]);
        $reviews = new ReviewService(new ReviewStoreAdapter());
        $review = $reviews->submit($customerId, 'product-1', ['order_id' => $orderId, 'order_line_id' => $lineId, 'rating' => 5, 'body' => 'Great']);
        self::assertSame('pending', $review['status']);
        try {
            $reviews->submit($customerId, 'product-1', ['order_id' => $orderId, 'order_line_id' => $lineId, 'rating' => 4, 'body' => 'Again']);
            self::fail('Expected duplicate review rejection');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('already', $e->getMessage());
        }
        $reviews->moderate($review['id'], 'approved', 'checked');
        self::assertCount(1, $reviews->published('product-1'));
    }

    public function testCmsWishlistAndReviewApiRoutesHavePublicAndPrivateBoundaries(): void
    {
        (new CmsService(new CmsStoreAdapter()))->create(['slug' => 'api-page', 'title' => 'API page', 'status' => 'published', 'store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND']);
        $router = new Router();
        $app = new \stdClass();
        $app->router = $router;
        require dirname(__DIR__, 2) . '/routes/api.php';

        self::assertSame(200, $router->dispatch(new Request('GET', '/api/pages/api-page', [], ['X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND']))->statusCode());
        self::assertSame(401, $router->dispatch(new Request('GET', '/api/wishlist'))->statusCode());
        self::assertSame(401, $router->dispatch(new Request('POST', '/api/products/product-1/reviews'))->statusCode());
        self::assertSame(401, $router->dispatch(new Request('GET', '/api/admin/pages'))->statusCode());
    }

    public function testPublishedCmsPagesAreIsolatedByCommerceScope(): void
    {
        $cms = new CmsService(new CmsStoreAdapter());
        $first = new CommerceScope('store-a', 'web', 'en', 'VND');
        $second = new CommerceScope('store-b', 'web', 'en', 'VND');
        $cms->create(['slug' => 'scoped-home', 'title' => 'A', 'status' => 'published'] + $first->metadata());
        $cms->create(['slug' => 'scoped-home', 'title' => 'B', 'status' => 'published'] + $second->metadata());

        self::assertSame('A', $cms->published('scoped-home', 'en', $first)['title']);
        self::assertSame('B', $cms->published('scoped-home', 'en', $second)['title']);
        self::assertNotSame($cms->published('scoped-home', 'en', $first)['id'], $cms->published('scoped-home', 'en', $second)['id']);
    }

    public function testCmsPublishRestoreIsScopedAuditedByRevisionAndIdempotent(): void
    {
        $cms = new CmsService(new CmsStoreAdapter());
        $scope = new CommerceScope('store-c', 'web', 'en', 'VND');
        $actor = ['id' => 'cms-owner-1', 'role' => 'owner'];
        $page = $cms->create(['slug' => 'workflow-page', 'title' => 'Original', 'status' => 'draft'] + $scope->metadata());
        $cms->update($page['id'], ['title' => 'Edited'] + $scope->metadata());

        $published = $cms->publish($page['id'], $scope, 'cms-publish-1', $actor);
        self::assertSame('published', $published['status']);
        self::assertSame($published['id'], $cms->publish($page['id'], $scope, 'cms-publish-1', $actor)['id']);

        $revisions = $cms->revisions('page', $page['id'], $scope, $actor);
        self::assertNotEmpty($revisions);
        self::assertSame('cms-owner-1', $revisions[0]['actor_id']);
        self::assertSame('cms-publish-1', $revisions[0]['idempotency_key']);

        $original = end($revisions);
        self::assertIsArray($original);
        $restored = $cms->restore($page['id'], (string) $original['id'], $scope, 'cms-restore-1', $actor);
        self::assertSame('Original', $restored['title']);
        self::assertSame('Original', $cms->restore($page['id'], (string) $original['id'], $scope, 'cms-restore-1', $actor)['title']);

        $otherScope = new CommerceScope('store-d', 'web', 'en', 'VND');
        self::assertNull($cms->publish($page['id'], $otherScope, 'cms-other-scope', $actor));
    }

    public function testCmsPublishAndRestoreRoutesUseEnvelopeScopeAndIdempotency(): void
    {
        $scope = new CommerceScope('store-http', 'web', 'en', 'VND');
        $cms = new CmsService(new CmsStoreAdapter());
        $page = $cms->create(['slug' => 'http-workflow', 'title' => 'HTTP', 'status' => 'draft'] + $scope->metadata());
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $token = $auth->register(['name' => 'CMS owner', 'email' => 'cms-http@test', 'password' => 'secret123', 'role' => 'owner']);
        $headers = ['Authorization' => 'Bearer ' . $auth->login('cms-http@test', 'secret123')->access, 'X-Store-Id' => 'store-http', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND', 'Idempotency-Key' => 'http-publish-1'];
        $router = new Router(); $app = new \stdClass(); $app->router = $router; require dirname(__DIR__, 2) . '/routes/api.php';
        $published = $router->dispatch(new Request('POST', '/api/admin/pages/' . $page['id'] . '/publish', [], $headers));
        self::assertSame(200, $published->statusCode());
        ob_start(); $published->send(); $payload = json_decode((string) ob_get_clean(), true);
        self::assertTrue(is_array($payload) && ($payload['success'] ?? false));
        self::assertSame((string) $token['id'], (string) $cms->revisions('page', $page['id'], $scope, ['id' => $token['id'], 'role' => 'owner'])[0]['actor_id']);
    }
}
