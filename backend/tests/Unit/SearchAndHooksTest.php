<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Shared\Hooks\HookBus;
use App\Modules\Shared\Hooks\HookCatalog;
use App\Modules\Shared\Hooks\HookDecision;
use App\Modules\Shared\Hooks\HookHandler;
use App\Modules\Shared\Hooks\HookName;
use App\Modules\Smart\Application\SearchService;
use App\Modules\Smart\Contracts\SearchIndex;
use PHPUnit\Framework\TestCase;

final class SearchAndHooksTest extends TestCase
{
    public function testSearchServicePreservesResultsAndAddsFreshnessEvidence(): void
    {
        $index = new class implements SearchIndex {
            public function search(string $query): array { return ['products' => [], 'facets' => ['types' => ['product' => 0]]]; }
            public function status(): array { return ['mode' => 'queued', 'lag_seconds' => 12, 'stale' => true]; }
        };

        $result = (new SearchService($index))->query('lamp');
        self::assertSame(12, $result['index']['lag_seconds']);
        self::assertTrue($result['index']['stale']);
        self::assertSame(['product' => 0], $result['facets']['types']);
    }

    public function testTypedHookCatalogDispatchesWithStringCompatibility(): void
    {
        self::assertTrue(HookCatalog::contains(HookName::PricingBeforeQuote));
        self::assertContains('pricing.before_quote', HookCatalog::names());
        $bus = new HookBus();
        $bus->register(HookName::PricingBeforeQuote, new class implements HookHandler {
            public function id(): string { return 'typed'; }
            public function priority(): int { return 1; }
            public function handle(string $hook, array &$payload): HookDecision { $payload['typed'] = $hook; return HookDecision::continue(); }
        });

        self::assertSame('pricing.before_quote', $bus->dispatch('pricing.before_quote', [])['payload']['typed']);
    }
}
