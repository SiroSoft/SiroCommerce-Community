<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\TaxRule;
use App\Modules\Platform\Infrastructure\NativeTaxProvider;
use App\Support\Ids;
use Tests\TestCase;

final class NativeTaxProviderCompoundTest extends TestCase
{
    /** @param array<string, mixed> $rule */
    private function rule(array $rule): TaxRule
    {
        return TaxRule::create(array_merge([
            'id' => Ids::new(),
            'destination_country' => 'VN',
            'tax_class' => 'standard',
            'priority' => 0,
            'state' => 'active',
            'compound' => 0,
        ], $rule));
    }

    /** @return array{total_minor: int, lines: array<int, array<string, mixed>>, rules: array<int, array<string, mixed>>} */
    private function quote(int $lineTotal, bool $inclusive = false): array
    {
        /** @var array{total_minor: int, lines: array<int, array<string, mixed>>, rules: array<int, array<string, mixed>>} $result */
        $result = (new NativeTaxProvider())->calculate(
            ['currency' => 'VND', 'tax_inclusive' => $inclusive, 'context' => ['destination' => ['country' => 'VN']]],
            [['variant_id' => 'variant-tax', 'line_total_minor' => $lineTotal, 'tax_class' => 'standard']],
        );
        return $result;
    }

    public function testSingleRuleBehaviorIsUnchangedWithoutCompound(): void
    {
        $this->rule(['code' => 'vn-standard', 'rate_basis_points' => 1000, 'priority' => 1]);

        $result = $this->quote(10000);

        self::assertSame(1000, $result['total_minor']);
        self::assertSame('vn-standard', $result['rules'][0]['code']);
        self::assertSame('vn-standard', $result['lines'][0]['evidence']['code']);
        self::assertArrayNotHasKey('mode', $result['lines'][0]['evidence']);
    }

    public function testMostSpecificRuleStillWinsWhenNoRuleIsCompound(): void
    {
        $this->rule(['code' => 'country', 'rate_basis_points' => 1000, 'priority' => 99]);
        $this->rule(['code' => 'region', 'rate_basis_points' => 500, 'destination_region' => 'HCM', 'priority' => 1]);

        $result = (new NativeTaxProvider())->calculate(
            ['currency' => 'VND', 'context' => ['destination' => ['country' => 'VN', 'region' => 'HCM']]],
            [['line_total_minor' => 10000, 'tax_class' => 'standard']],
        );

        self::assertSame(500, $result['total_minor']);
        self::assertSame('region', $result['rules'][0]['code']);
    }

    public function testCompoundSumsEveryMatchingRuleInPriorityOrder(): void
    {
        $this->rule(['code' => 'priority-2', 'rate_basis_points' => 500, 'priority' => 2, 'compound' => 1]);
        $this->rule(['code' => 'priority-1', 'rate_basis_points' => 1000, 'priority' => 1, 'compound' => 1]);
        $this->rule(['code' => 'priority-3', 'rate_basis_points' => 200, 'priority' => 3]);

        $result = $this->quote(10000);

        self::assertSame(1700, $result['total_minor']);
        $evidence = $result['lines'][0]['evidence'];
        self::assertSame('compound', $evidence['mode']);
        self::assertSame(['priority-1', 'priority-2', 'priority-3'], $evidence['codes']);
        self::assertCount(3, $result['rules']);
        self::assertSame(['priority-1', 'priority-2', 'priority-3'], array_column($result['rules'], 'code'));
    }

    public function testCompoundOrderUsesRateDescendingForEqualPriority(): void
    {
        $this->rule(['code' => 'low', 'rate_basis_points' => 250, 'priority' => 4, 'compound' => 1]);
        $this->rule(['code' => 'high', 'rate_basis_points' => 750, 'priority' => 4, 'compound' => 1]);

        $result = $this->quote(10000);

        self::assertSame(['high', 'low'], $result['lines'][0]['evidence']['codes']);
    }

    public function testInclusiveCompoundAppliesMathPerRateThenSums(): void
    {
        $this->rule(['code' => 'inclusive-a', 'rate_basis_points' => 1000, 'priority' => 1, 'compound' => 1]);
        $this->rule(['code' => 'inclusive-b', 'rate_basis_points' => 1000, 'priority' => 2, 'compound' => 1]);

        $result = $this->quote(10000, true);

        self::assertSame(1818, $result['total_minor']);
        self::assertSame(1818, $result['lines'][0]['tax_minor']);
    }

    public function testCompoundTaxNeverExceedsLineTotal(): void
    {
        $this->rule(['code' => 'cap-a', 'rate_basis_points' => 8000, 'priority' => 1, 'compound' => 1]);
        $this->rule(['code' => 'cap-b', 'rate_basis_points' => 8000, 'priority' => 2, 'compound' => 1]);

        $result = $this->quote(1000);

        self::assertSame(1000, $result['total_minor']);
        self::assertSame(1000, $result['lines'][0]['tax_minor']);
    }

    public function testNoMatchingRuleIsZeroTaxOutsideProduction(): void
    {
        $result = (new NativeTaxProvider())->calculate(
            ['currency' => 'VND', 'context' => ['destination' => ['country' => 'US']]],
            [['line_total_minor' => 10000, 'tax_class' => 'standard']],
        );

        self::assertSame(0, $result['total_minor']);
        self::assertSame('no_active_matching_rule', $result['lines'][0]['evidence']['reason']);
        self::assertSame('explicit-zero-tax-no-match', $result['rules'][0]['code']);
    }

    public function testEffectiveAndExpiryWindowIsRespected(): void
    {
        $this->rule([
            'code' => 'future',
            'rate_basis_points' => 1000,
            'effective_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);
        self::assertSame(0, $this->quote(10000)['total_minor']);

        TaxRule::query()->where('code', '=', 'future')->get()[0]->delete();
        $this->rule([
            'code' => 'expired',
            'rate_basis_points' => 1000,
            'expires_at' => date('Y-m-d H:i:s', time() - 3600),
        ]);
        self::assertSame(0, $this->quote(10000)['total_minor']);

        TaxRule::query()->where('code', '=', 'expired')->get()[0]->delete();
        $this->rule([
            'code' => 'current',
            'rate_basis_points' => 1000,
            'effective_at' => date('Y-m-d H:i:s', time() - 60),
            'expires_at' => date('Y-m-d H:i:s', time() + 60),
        ]);
        self::assertSame(1000, $this->quote(10000)['total_minor']);
    }

    public function testProductionStillFailsClosedWhenNoRuleMatches(): void
    {
        $previous = getenv('APP_ENV');
        putenv('APP_ENV=production');
        try {
            $this->expectException(\RuntimeException::class);
            (new NativeTaxProvider())->calculate(
                ['currency' => 'VND', 'context' => ['destination' => ['country' => 'ZZ']]],
                [['line_total_minor' => 1000, 'tax_class' => 'standard']],
            );
        } finally {
            $previous === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $previous);
        }
    }
}
