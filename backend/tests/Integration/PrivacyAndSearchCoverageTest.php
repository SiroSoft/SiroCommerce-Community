<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Platform\Infrastructure\CustomerPrivacyService;
use App\Modules\Platform\Infrastructure\NativeSearchAdapter;
use App\Modules\Search\Contracts\SearchTransport;
use App\Repositories\OrderRepository;
use Siro\Core\Database;
use Tests\TestCase;

final class PrivacyAndSearchCoverageTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $envBackup = [];

    /** @var array<int, string> */
    private const ENV_KEYS = [
        'SEARCH_PROVIDER', 'SEARCH_INDEX_VERSION', 'SEARCH_MAX_LAG_SECONDS',
        'SEARCH_URL', 'SEARCH_API_KEY', 'SEARCH_SYNONYMS', 'SEARCH_RANKING', 'SEARCH_TYPO_TOLERANCE',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::ENV_KEYS as $key) {
            $existing = $_ENV[$key] ?? getenv($key);
            $this->envBackup[$key] = is_string($existing) ? $existing : false;
            putenv($key);
            unset($_ENV[$key]);
        }
        Database::table('products')->insert([
            'id' => 'search-product-1', 'name' => 'Neon Lamp', 'slug' => 'neon-lamp',
            'description' => 'A bright neon lamp', 'status' => 'active', 'store_id' => 'default',
            'channel_id' => 'default', 'locale' => 'en', 'currency' => 'USD',
            'category_id' => 'cat-lighting', 'brand_id' => 'brand-neon', 'type' => 'simple',
            'tags_json' => '["sale","new"]',
        ]);
        Database::table('customers')->insert([
            'id' => 'search-customer-1', 'name' => 'Neon Buyer', 'email' => 'neon-buyer@example.test',
            'created_at' => '2026-09-10 10:00:00',
        ]);
        (new OrderRepository())->createOrder([
            'id' => str_pad('SEARCH-ORDER-1', 26, '0'), 'number' => 'SEARCH-0001', 'customer_id' => 'search-customer-1',
            'currency' => 'USD', 'subtotal_minor' => 1000, 'grand_total_minor' => 1000,
            'state' => 'paid', 'idempotency_key' => 'search-order-1', 'store_id' => 'default', 'channel_id' => 'default',
        ]);
    }

    protected function tearDown(): void
    {
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

    public function testNativeSearchSearchValidationFiltersFacetsAndProviderPath(): void
    {
        $adapter = new NativeSearchAdapter();

        foreach ([
            ['q' => ''],
            ['q' => str_repeat('x', 81)],
            ['q' => 'lamp', 'type' => 'bogus'],
            ['q' => 'lamp', 'filters' => 'bad'],
            ['q' => 'lamp', 'filters' => ['bogus' => 'x']],
            ['q' => 'lamp', 'facets' => 'bad'],
            ['q' => 'lamp', 'facets' => ['bogus']],
            ['q' => 'lamp', 'facets' => ['status', 'type', 'brand_id', 'category', 'tag']],
        ] as $request) {
            try {
                $adapter->search($request);
                self::fail('Search request should be rejected: ' . json_encode($request));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $catalog = $adapter->search([
            'q' => 'Neon', 'type' => 'catalog', 'store_id' => 'default', 'channel_id' => 'default',
            'filters' => ['status' => 'active', 'category' => 'cat-lighting', 'tag' => 'sale'],
            'facets' => ['status', 'category', 'brand_id', 'tag'], 'page' => 1, 'per_page' => 10,
        ]);
        self::assertSame('native', $catalog['mode']);
        self::assertSame('search-product-1', $catalog['data'][0]['id'] ?? null);
        self::assertSame(['active' => 1], $catalog['facets']['status']);
        self::assertSame(['cat-lighting' => 1], $catalog['facets']['category']);
        self::assertSame(['brand-neon' => 1], $catalog['facets']['brand_id']);
        self::assertSame(['new' => 1, 'sale' => 1], $catalog['facets']['tag']);

        $orders = $adapter->search(['q' => 'SEARCH-0001', 'type' => 'orders', 'filters' => ['state' => 'paid']]);
        self::assertSame('SEARCH-0001', $orders['data'][0]['number'] ?? null);

        $customers = $adapter->search(['q' => 'Neon', 'type' => 'customers']);
        self::assertSame('n***@example.test', $customers['data'][0]['email_masked'] ?? null);

        $transport = new class implements SearchTransport {
            /** @param array<string, mixed> $body @return array<string, mixed> */
            public function search(string $index, array $body): array
            {
                return ['data' => [['id' => 'provider-1']], 'meta' => ['total' => 1]];
            }
        };
        $provider = (new NativeSearchAdapter($transport))->search(['q' => 'Neon', 'type' => 'catalog']);
        self::assertSame('provider', $provider['mode']);
        self::assertSame('provider-1', $provider['data'][0]['id'] ?? null);
        self::assertSame(['type' => ['catalog' => 1]], $provider['facets']);
    }

    public function testNativeSearchStatusReindexCheckpointAndDeadLetters(): void
    {
        $adapter = new NativeSearchAdapter();
        $status = $adapter->status();
        self::assertSame('native', $status['provider']);
        self::assertTrue($status['configured']);
        self::assertTrue($status['ready']);

        $this->setEnv([
            'SEARCH_PROVIDER' => 'meilisearch', 'SEARCH_INDEX_VERSION' => 'idx-1',
            'SEARCH_URL' => 'https://search.example.test', 'SEARCH_API_KEY' => 'search-key',
        ]);
        $missingCheckpoint = $adapter->status();
        self::assertTrue($missingCheckpoint['configured']);
        self::assertFalse($missingCheckpoint['ready']);

        Database::table('search_index_checkpoints')->insert([
            'index_name' => 'idx-1', 'cursor' => null, 'version' => 'idx-1', 'state' => 'ready',
            'processed' => 5, 'failed' => 0, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $ready = $adapter->status();
        self::assertTrue($ready['ready']);
        self::assertSame('ready', $ready['checkpoint']['state'] ?? null);

        $adapter->deadLetter('idx-1', 'doc-1', str_repeat('x', 3000));
        $withDeadLetter = $adapter->status();
        self::assertSame(1, $withDeadLetter['unresolved_dead_letters']);
        self::assertFalse($withDeadLetter['ready']);

        $this->setEnv(['SEARCH_PROVIDER' => 'meilisearch', 'SEARCH_URL' => 'not-a-url']);
        self::assertFalse($adapter->status()['configured']);

        $this->setEnv(['SEARCH_PROVIDER' => 'native', 'SEARCH_INDEX_VERSION' => 'idx-reindex']);
        $queued = $adapter->enqueueReindex(['type' => 'catalog']);
        self::assertFalse($queued['idempotent']);
        $replayed = $adapter->enqueueReindex(['type' => 'catalog']);
        self::assertTrue($replayed['idempotent']);
        self::assertSame($queued['id'], $replayed['id']);
        try {
            $adapter->enqueueReindex(['type' => 'bogus']);
            self::fail('Unsupported reindex types must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }

        $adapter->checkpoint('idx-reindex', 'cursor-1', 'running', 3, 1);
        $rows = Database::select('SELECT processed, failed, cursor, state FROM search_index_checkpoints WHERE index_name = ?', ['idx-reindex']);
        self::assertSame(3, (int) ($rows[0]['processed'] ?? -1));
        self::assertSame('cursor-1', $rows[0]['cursor'] ?? null);
    }

    public function testNativeSearchSynonymAndRankingValidation(): void
    {
        $adapter = new NativeSearchAdapter();

        $this->setEnv(['SEARCH_SYNONYMS' => 'lamp,light']);
        $valid = $adapter->search(['q' => 'Neon', 'type' => 'catalog']);
        self::assertSame('native', $valid['mode']);

        $this->setEnv(['SEARCH_SYNONYMS' => "bad\x01synonym"]);
        try {
            $adapter->search(['q' => 'Neon', 'type' => 'catalog']);
            self::fail('Control characters in synonyms must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('synonym', $error->getMessage());
        }

        $this->setEnv(['SEARCH_SYNONYMS' => implode(',', array_map(static fn (int $i): string => 's' . $i, range(1, 101)))]);
        try {
            $adapter->search(['q' => 'Neon', 'type' => 'catalog']);
            self::fail('Too many synonyms must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('Too many', $error->getMessage());
        }

        $this->setEnv(['SEARCH_SYNONYMS' => '', 'SEARCH_RANKING' => '{"text":60,"exact":25,"freshness":10,"availability":5}']);
        self::assertSame('native', $adapter->search(['q' => 'Neon', 'type' => 'catalog'])['mode']);

        foreach ([
            '{"text":60}',
            '{"text":60,"exact":25,"freshness":10,"availability":5,"extra":0}',
            '{"text":"x","exact":25,"freshness":10,"availability":5}',
            '{"text":-1,"exact":25,"freshness":10,"availability":66}',
            '{"text":10,"exact":10,"freshness":10,"availability":10}',
        ] as $ranking) {
            $this->setEnv(['SEARCH_SYNONYMS' => '', 'SEARCH_RANKING' => $ranking]);
            try {
                $adapter->search(['q' => 'Neon', 'type' => 'catalog']);
                self::fail('Invalid ranking must be rejected: ' . $ranking);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCustomerPrivacyExportAnonymizeConsentAndIdempotency(): void
    {
        $service = new CustomerPrivacyService();
        $now = date('Y-m-d H:i:s');
        Database::table('customers')->insert([
            'id' => 'privacy-customer-1', 'name' => 'Privacy User', 'email' => 'privacy@example.test',
            'created_at' => $now,
        ]);
        (new OrderRepository())->createOrder([
            'id' => str_pad('PRIVACY-ORDER-1', 26, '0'), 'number' => 'PRIVACY-0001', 'customer_id' => 'privacy-customer-1',
            'guest_email' => 'order-private@example.test',
            'currency' => 'USD', 'subtotal_minor' => 1000, 'grand_total_minor' => 1000,
            'state' => 'paid', 'idempotency_key' => 'privacy-order-1',
        ]);
        Database::table('addresses')->insert([
            'id' => 'privacy-address-1', 'customer_id' => 'privacy-customer-1', 'label' => 'home',
            'line1' => '1 Privacy Way', 'city' => 'HCM', 'phone' => '0900000000',
        ]);
        Database::table('customer_notification_preferences')->insert([
            'customer_id' => 'privacy-customer-1', 'transactional_email' => 1, 'marketing_email' => 1,
            'idempotency_key' => null, 'request_fingerprint' => null, 'updated_at' => $now,
        ]);
        Database::table('consent_audit_events')->insert([
            'id' => 'privacy-consent-1', 'customer_id' => 'privacy-customer-1', 'purpose' => 'marketing',
            'granted' => 1, 'version' => 'v1', 'source' => 'account', 'occurred_at' => $now,
        ]);

        try {
            $service->export('privacy-customer-1', '');
            self::fail('Missing idempotency keys must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('Idempotency-Key', $error->getMessage());
        }
        try {
            $service->export('missing-customer', 'privacy-key-1');
            self::fail('Missing customers must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Customer not found', $error->getMessage());
        }

        $exported = $service->export('privacy-customer-1', 'privacy-key-1');
        self::assertSame('privacy@example.test', $exported['customer']['email'] ?? null);
        self::assertCount(1, $exported['orders']);
        self::assertCount(1, $exported['addresses']);
        self::assertCount(1, $exported['consents']);
        self::assertSame(1, $exported['notification_preferences']['marketing_email'] ?? null);

        $replayed = $service->export('privacy-customer-1', 'privacy-key-1');
        self::assertSame($exported['customer']['id'], $replayed['customer']['id'] ?? null);
        self::assertCount(1, $replayed['orders']);
        self::assertCount(1, $replayed['addresses']);
        try {
            $service->anonymize('privacy-customer-1', 'privacy-key-1');
            self::fail('Reusing an idempotency key for another operation must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('reused', $error->getMessage());
        }

        $held = $service->setLegalHold('privacy-customer-1', true, 'privacy-hold-1');
        self::assertSame(1, $held['legal_hold']);
        try {
            $service->anonymize('privacy-customer-1', 'privacy-anon-1');
            self::fail('Legal holds must block anonymization.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('legal hold', $error->getMessage());
        }
        $service->setLegalHold('privacy-customer-1', false, 'privacy-hold-2');
        Database::execute('UPDATE privacy_requests SET legal_hold = 0 WHERE customer_id = ?', ['privacy-customer-1']);
        $orderId = str_pad('PRIVACY-ORDER-1', 26, '0');
        Database::table('guest_order_access')->insert([
            'id' => 'privacy-guest-access-1', 'order_id' => $orderId,
            'token_hash' => hash('sha256', 'guest-secret'), 'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'revoked_at' => null,
        ]);
        $anonymized = $service->anonymize('privacy-customer-1', 'privacy-anon-2');
        self::assertSame('anonymized', $anonymized['state']);
        $rows = Database::select('SELECT email, name FROM customers WHERE id = ?', ['privacy-customer-1']);
        self::assertStringEndsWith('@invalid.local', (string) ($rows[0]['email'] ?? ''));
        self::assertSame('Deleted customer', $rows[0]['name'] ?? null);
        $orderRows = Database::select('SELECT guest_email FROM orders WHERE id = ?', [$orderId]);
        self::assertNull($orderRows[0]['guest_email'] ?? null);
        $accessRows = Database::select('SELECT revoked_at FROM guest_order_access WHERE order_id = ?', [$orderId]);
        self::assertNotEmpty($accessRows[0]['revoked_at'] ?? null);

        try {
            $service->recordConsent('privacy-customer-1', ['purpose' => '', 'version' => '', 'source' => '', 'granted' => 'yes']);
            self::fail('Invalid consent contracts must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('Invalid consent contract', $error->getMessage());
        }
        try {
            $service->recordConsent('privacy-customer-1', ['purpose' => 'bogus', 'version' => 'v1', 'source' => 'account', 'granted' => true]);
            self::fail('Unsupported consent purposes must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('Unsupported consent purpose', $error->getMessage());
        }
        try {
            $service->recordConsent('missing-customer', ['purpose' => 'analytics', 'version' => 'v1', 'source' => 'account', 'granted' => true]);
            self::fail('Missing customers must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Customer not found', $error->getMessage());
        }
        $consent = $service->recordConsent('privacy-customer-1', ['purpose' => 'analytics', 'version' => 'v1', 'source' => 'account', 'granted' => true]);
        self::assertSame('analytics', $consent['purpose']);
        self::assertSame(1, $consent['granted']);
    }

    /** @param array<string, string> $values */
    private function setEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}
