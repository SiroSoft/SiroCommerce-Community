<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Platform\Infrastructure\NativeSearchAdapter;
use App\Modules\Search\Infrastructure\HttpSearchTransport;
use PHPUnit\Framework\TestCase;
use Siro\Core\HttpResponse;

final class SearchEcosystemTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['SEARCH_PROVIDER', 'SEARCH_URL', 'SEARCH_API_KEY', 'SEARCH_INDEX_VERSION'] as $name) $this->environment[$name] = getenv($name);
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) $value === false ? putenv($name) : putenv($name . '=' . $value);
        parent::tearDown();
    }

    public function testUnsafeFilterIsRejectedBeforeQueryConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new NativeSearchAdapter())->search(['q' => 'shoe', 'filters' => ['email' => 'a@example.test']]);
    }

    public function testSearchConfigurationHasBoundedRequestShape(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new NativeSearchAdapter())->search(['q' => str_repeat('x', 81)]);
    }

    public function testConfiguredTransportPropagatesValidatedOptions(): void
    {
        putenv('SEARCH_PROVIDER=elasticsearch');
        putenv('SEARCH_URL=https://search.example.test');
        putenv('SEARCH_API_KEY=secret');
        $captured = [];
        $transport = new HttpSearchTransport(function (string $url, string $body, array $headers) use (&$captured): HttpResponse {
            $captured = [$url, json_decode($body, true), $headers];
            return new HttpResponse(200, json_encode(['hits' => ['total' => ['value' => 1], 'hits' => [['_source' => ['id' => 'p1']]]], 'aggregations' => ['status' => ['buckets' => [['key' => 'active', 'doc_count' => 1]]]]]), '');
        });

        $result = $transport->search('catalog-v1-catalog', ['query' => 'shoe', 'filters' => ['status' => 'active'], 'facets' => ['status'], 'ranking' => ['text' => 60, 'exact' => 25, 'freshness' => 10, 'availability' => 5], 'synonyms' => ['sneaker'], 'typo_tolerance' => true]);
        self::assertSame('/catalog-v1-catalog/_search', parse_url($captured[0], PHP_URL_PATH));
        self::assertSame(['term' => ['status' => 'active']], $captured[1]['query']['bool']['filter'][0]);
        self::assertSame(['status'], array_keys($captured[1]['aggs']));
        self::assertSame(60, $captured[1]['ranking']['text']);
        self::assertSame(['sneaker'], $captured[1]['synonyms']);
        self::assertSame(true, $captured[1]['typo_tolerance']);
        self::assertSame(['id' => 'p1'], $result['data'][0]);
        self::assertSame(['active' => 1], $result['facets']['status']);
    }

    public function testMeilisearchResponseIsNormalized(): void
    {
        putenv('SEARCH_PROVIDER=meilisearch');
        putenv('SEARCH_URL=https://search.example.test');
        putenv('SEARCH_API_KEY=secret');
        $transport = new HttpSearchTransport(static fn (): HttpResponse => new HttpResponse(200, json_encode(['hits' => [['id' => 'p1']], 'estimatedTotalHits' => 3, 'facetDistribution' => ['status' => ['active' => 3]]]), ''));
        $result = $transport->search('catalog-v1-catalog', ['query' => 'shoe', 'ranking' => ['text' => 60, 'exact' => 25, 'freshness' => 10, 'availability' => 5], 'synonyms' => [], 'typo_tolerance' => false]);
        self::assertSame(['id' => 'p1'], $result['data'][0]);
        self::assertSame(3, $result['meta']['total']);
        self::assertSame(['active' => 3], $result['facets']['status']);
    }

    public function testScopedRequestKeepsLocaleAndCurrencyAtTheAdapterBoundary(): void
    {
        putenv('SEARCH_PROVIDER=elasticsearch');
        putenv('SEARCH_URL=https://search.example.test');
        putenv('SEARCH_API_KEY=secret');
        $captured = [];
        $transport = new HttpSearchTransport(function (string $url, string $body, array $headers) use (&$captured): HttpResponse {
            $captured = json_decode($body, true);
            return new HttpResponse(200, json_encode(['hits' => ['total' => ['value' => 0], 'hits' => []]]), '');
        });

        (new NativeSearchAdapter($transport))->search(['q' => 'shoe', 'store_id' => 'store-a', 'channel_id' => 'web', 'locale' => 'vi', 'currency' => 'VND', 'facets' => ['locale', 'currency']]);

        self::assertSame(['term' => ['store_id' => 'store-a']], $captured['query']['bool']['filter'][0]);
        self::assertSame(['term' => ['locale' => 'vi']], $captured['query']['bool']['filter'][2]);
        self::assertSame(['locale', 'currency'], array_keys($captured['aggs']));
    }

    public function testProviderReadinessFailsClosedForMissingKeyAndInvalidIndex(): void
    {
        putenv('SEARCH_PROVIDER=elasticsearch');
        putenv('SEARCH_URL=https://search.example.test');
        putenv('SEARCH_API_KEY=');
        putenv('SEARCH_INDEX_VERSION=');
        $status = (new NativeSearchAdapter())->status();
        self::assertFalse($status['configured']);
        self::assertFalse($status['ready']);
    }
}
