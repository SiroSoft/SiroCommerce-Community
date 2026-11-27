<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Review\Application\ReviewService;
use App\Modules\Review\Contracts\ReviewStore;
use Siro\Core\Database;
use Siro\Core\Event;
use Tests\TestCase;

/** Batch 1 Platform §2.3: review + abandoned-cart events and scheduler. */
final class Batch1PlatformEventsTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $seenSubmitted = [];
    /** @var list<array<string, mixed>> */
    private array $seenApproved = [];
    /** @var list<array<string, mixed>> */
    private array $seenAbandoned = [];

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The scheduler boots the app, which re-reads DB config from env;
        // point it at this test's isolated sqlite file (LaunchCommandCoverageTest pattern).
        foreach (['DB_CONNECTION', 'DB_DATABASE'] as $key) {
            $existing = $_ENV[$key] ?? getenv($key);
            $this->envBackup[$key] = is_string($existing) ? $existing : false;
        }
        $this->setEnv(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->sqlitePath()]);
        Event::flush();
        Event::on('review.submitted', function (mixed $payload): void {
            if (is_array($payload)) $this->seenSubmitted[] = $payload;
        });
        Event::on('review.approved', function (mixed $payload): void {
            if (is_array($payload)) $this->seenApproved[] = $payload;
        });
        Event::on('cart.abandoned', function (mixed $payload): void {
            if (is_array($payload)) $this->seenAbandoned[] = $payload;
        });
    }

    protected function tearDown(): void
    {
        Event::flush();
        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
        parent::tearDown();
    }

    private function sqlitePath(): string
    {
        $test = md5($this->name() . ':' . getmypid());
        return dirname(__DIR__, 2) . '/storage/tests/' . str_replace('\\', '_', static::class) . '_' . $test . '.db';
    }

    /** @param array<string, string> $env */
    private function setEnv(array $env): void
    {
        foreach ($env as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }

    /** @return array<string, mixed> */
    private function runScheduler(string ...$args): array
    {
        $command = new \App\Console\Commands\ScheduledCommerceOperationsCommand(dirname(__DIR__, 2));
        ob_start();
        $exit = $command->run(array_merge(['commerce:scheduled'], $args));
        $output = (string) ob_get_clean();
        self::assertSame(0, $exit, $output);
        $decoded = json_decode($output, true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    public function testReviewSubmitEmitsSubmittedEvent(): void
    {
        $service = new ReviewService(new Batch1StubReviewStore());
        $result = $service->submit('cust-1', 'prod-1', ['order_id' => 'ord-1', 'order_line_id' => 'line-1', 'rating' => 5, 'body' => 'Great product']);
        self::assertSame('rev-1', $result['id'] ?? null);
        self::assertCount(1, $this->seenSubmitted);
        $payload = $this->seenSubmitted[0];
        self::assertSame('rev-1', $payload['review_id'] ?? null);
        self::assertSame('prod-1', $payload['product_id'] ?? null);
        self::assertSame('cust-1', $payload['customer_id'] ?? null);
        self::assertSame(5, $payload['rating'] ?? null);
        self::assertNotEmpty($payload['at'] ?? null);
    }

    public function testReviewSubmitStillSucceedsWhenAListenerThrows(): void
    {
        Event::on('review.submitted', static function (): void {
            throw new \RuntimeException('listener boom');
        });
        $service = new ReviewService(new Batch1StubReviewStore());
        $result = $service->submit('cust-1', 'prod-1', ['order_id' => 'ord-1', 'order_line_id' => 'line-1', 'rating' => 4, 'body' => 'Good']);
        self::assertSame('rev-1', $result['id'] ?? null);
    }

    public function testReviewModerateApprovedEmitsApprovedEvent(): void
    {
        $service = new ReviewService(new Batch1StubReviewStore());
        $review = $service->moderate('rev-1', 'approved', '');
        self::assertNotNull($review);
        self::assertCount(1, $this->seenApproved);
        $payload = $this->seenApproved[0];
        self::assertSame('rev-1', $payload['review_id'] ?? null);
        self::assertSame('prod-1', $payload['product_id'] ?? null);
        self::assertNotEmpty($payload['at'] ?? null);
    }

    public function testReviewModerateRejectedOrMissingEmitsNothing(): void
    {
        $service = new ReviewService(new Batch1StubReviewStore());
        self::assertNotNull($service->moderate('rev-1', 'rejected', 'spam'));
        self::assertNull($service->moderate('missing', 'approved', ''));
        self::assertSame([], $this->seenApproved);
    }

    public function testSchedulerEmitsCartAbandonedForEligibleCart(): void
    {
        $now = date('Y-m-d H:i:s');
        $stale = date('Y-m-d H:i:s', time() - 2400); // 40 min ago (> 30 min default)
        Database::table('carts')->insert([
            'id' => 'cart-ab-1', 'customer_id' => 'cust-ab', 'currency' => 'VND', 'state' => 'open',
            'expires_at' => date('Y-m-d H:i:s', time() + 86400), 'created_at' => $stale, 'updated_at' => $stale,
        ]);
        Database::table('customer_notification_preferences')->insert([
            'customer_id' => 'cust-ab', 'transactional_email' => 1, 'marketing_email' => 1,
            'idempotency_key' => null, 'request_fingerprint' => null, 'updated_at' => $now,
        ]);
        // Fresh cart: updated 5 min ago — not yet past the threshold.
        Database::table('carts')->insert([
            'id' => 'cart-ab-2', 'customer_id' => 'cust-ab', 'currency' => 'VND', 'state' => 'open',
            'expires_at' => date('Y-m-d H:i:s', time() + 86400), 'created_at' => $now, 'updated_at' => $now,
        ]);
        // Stale cart without marketing consent — must not schedule.
        Database::table('carts')->insert([
            'id' => 'cart-ab-3', 'customer_id' => 'cust-noconsent', 'currency' => 'VND', 'state' => 'open',
            'expires_at' => date('Y-m-d H:i:s', time() + 86400), 'created_at' => $stale, 'updated_at' => $stale,
        ]);

        $result = $this->runScheduler('--apply', '--limit=100');
        self::assertSame(1, $result['abandoned_carts']['scheduled'] ?? null);
        self::assertSame(1, $result['abandoned_carts']['emitted'] ?? null);

        $rows = Database::select('SELECT * FROM marketing_automation_deliveries WHERE cart_id = ?', ['cart-ab-1']);
        self::assertCount(1, $rows);
        self::assertSame('abandoned_cart', $rows[0]['signal'] ?? null);
        self::assertCount(1, $this->seenAbandoned);
        $payload = $this->seenAbandoned[0];
        self::assertSame('cart-ab-1', $payload['cart_id'] ?? null);
        self::assertSame('cust-ab', $payload['customer_id'] ?? null);
        self::assertIsArray($payload['totals_minor'] ?? null);
        self::assertArrayHasKey('total_minor', $payload['totals_minor']);
        self::assertNotEmpty($payload['at'] ?? null);

        self::assertSame([], Database::select('SELECT * FROM marketing_automation_deliveries WHERE cart_id = ?', ['cart-ab-2']));
        self::assertSame([], Database::select('SELECT * FROM marketing_automation_deliveries WHERE cart_id = ?', ['cart-ab-3']));
    }

    public function testSchedulerDryRunSchedulesNothing(): void
    {
        $stale = date('Y-m-d H:i:s', time() - 2400);
        Database::table('carts')->insert([
            'id' => 'cart-ab-dry', 'customer_id' => 'cust-ab', 'currency' => 'VND', 'state' => 'open',
            'expires_at' => date('Y-m-d H:i:s', time() + 86400), 'created_at' => $stale, 'updated_at' => $stale,
        ]);
        Database::table('customer_notification_preferences')->insert([
            'customer_id' => 'cust-ab', 'transactional_email' => 1, 'marketing_email' => 1,
            'idempotency_key' => null, 'request_fingerprint' => null, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $result = $this->runScheduler('--dry-run', '--limit=100');
        self::assertSame(0, $result['abandoned_carts']['scheduled'] ?? null);
        self::assertSame(1, $result['abandoned_carts']['skipped'] ?? null);
        self::assertSame([], Database::select('SELECT * FROM marketing_automation_deliveries WHERE cart_id = ?', ['cart-ab-dry']));
        self::assertSame([], $this->seenAbandoned);
    }
}

final class Batch1StubReviewStore implements ReviewStore
{
    /** @return array<int, array<string, mixed>> */
    public function published(string $productId): array { return []; }

    /** @return array<string, mixed>|null */
    public function ownedOrderLine(string $customerId, string $orderId, string $lineId): ?array
    {
        return ['variant_id' => 'var-1', 'product_id' => 'prod-1', 'order_state' => 'delivered'];
    }

    public function alreadySubmitted(string $customerId, string $lineId): bool { return false; }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(array $data): array { return ['id' => 'rev-1', 'status' => 'pending'] + $data; }

    /** @return array<int, array<string, mixed>> */
    public function all(): array { return []; }

    /** @return array<string, mixed>|null */
    public function moderate(string $id, string $status, string $note): ?array
    {
        if ($id !== 'rev-1') return null;
        return ['id' => 'rev-1', 'product_id' => 'prod-1', 'status' => $status, 'moderation_note' => $note];
    }
}
