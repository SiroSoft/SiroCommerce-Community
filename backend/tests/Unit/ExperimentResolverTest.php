<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Experimentation\Domain\ExperimentResolver;
use App\Support\CommerceScope;
use PHPUnit\Framework\TestCase;

final class ExperimentResolverTest extends TestCase
{
    private function scope(string $store = 'store-a'): CommerceScope { return new CommerceScope($store, 'web', 'en', 'USD'); }

    public function testAssignmentIsStableAndScopeIsolated(): void
    {
        $config = ExperimentResolver::sanitize([['id' => 'hero', 'variants' => [['id' => 'control', 'weight' => 5000], ['id' => 'alt', 'weight' => 5000]]]]);
        self::assertSame(ExperimentResolver::assign($config, $this->scope(), 'anonymous-session', true), ExperimentResolver::assign($config, $this->scope(), 'anonymous-session', true));
        self::assertNotSame(ExperimentResolver::assign($config, $this->scope(), 'anonymous-session', true), ExperimentResolver::assign($config, $this->scope('store-b'), 'anonymous-session', true));
    }

    public function testOptOutUsesControlAndInvalidConfigFallsBack(): void
    {
        $config = ExperimentResolver::sanitize([['id' => 'hero', 'variants' => [['id' => 'control', 'weight' => 1], ['id' => 'alt', 'weight' => 1]]]]);
        self::assertSame(['hero' => 'control'], ExperimentResolver::assign($config, $this->scope(), 'session', false));
        self::assertSame([], ExperimentResolver::sanitize([['id' => 'bad!', 'variants' => [['id' => 'control', 'weight' => 1]]]]));
        self::assertSame([], ExperimentResolver::sanitize([['id' => 'hero', 'variants' => [['id' => 'control', 'weight' => 10001]]]]));
    }
}
