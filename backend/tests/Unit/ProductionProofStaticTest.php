<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductionProofStaticTest extends TestCase
{
    public function testProductionProofStaticGatePasses(): void
    {
        $base = dirname(__DIR__, 2);
        $output = [];
        $exit = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($base . '/scripts/check-production-proof.php'), $output, $exit);

        self::assertSame(0, $exit, implode(PHP_EOL, $output));
        self::assertStringContainsString('production proof static checks passed', implode(PHP_EOL, $output));
    }

    public function testLoadProofHasExplicitBounds(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/perf/k6/production-proof.js');

        self::assertStringContainsString('Math.min', $source);
        self::assertStringContainsString('20)', $source);
        self::assertStringContainsString('PROFILE', $source);
        self::assertStringContainsString('soak', $source);
        self::assertStringContainsString('X-N-Plus-One', $source);
    }

    public function testBenchmarkContractHasStructuredProofStates(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/scripts/bench.php');

        self::assertStringContainsString("'schema_version' => 2", $source);
        self::assertStringContainsString("'preflight'", $source);
        self::assertStringContainsString("'latency'", $source);
        self::assertStringContainsString("'errors'", $source);
        self::assertStringContainsString("'db_queries'", $source);
        self::assertStringContainsString("'NOT_PROVEN'", $source);
    }

    public function testBenchmarkRejectsInvalidTargetAndProfileBeforeRequests(): void
    {
        $base = dirname(__DIR__, 2);
        foreach (['--base-url=file:///tmp/target', '--base-url=http://127.0.0.1:1 --profile=invalid'] as $arguments) {
            $output = [];
            $exit = 0;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($base . '/scripts/bench.php') . ' ' . $arguments . ' --dataset=10000 --samples=1', $output, $exit);
            self::assertSame(2, $exit, implode(PHP_EOL, $output));
        }
    }

    public function testUnreachableBenchmarkIsBlockedWithoutUndefinedHeaderAccess(): void
    {
        $base = dirname(__DIR__, 2);
        $artifactDir = sys_get_temp_dir() . '/sirocommerce-proof-' . bin2hex(random_bytes(4));
        mkdir($artifactDir, 0775, true);
        $output = [];
        $exit = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($base . '/scripts/bench.php') . ' --base-url=http://127.0.0.1:1 --dataset=10000 --samples=1 --artifact-dir=' . escapeshellarg($artifactDir), $output, $exit);
        $evidence = glob($artifactDir . '/*.json') ?: [];
        self::assertSame(0, $exit);
        self::assertStringContainsString('BLOCKED', implode(PHP_EOL, $output));
        self::assertStringContainsString('Target was unreachable', (string) file_get_contents($evidence[0] ?? ''));
        foreach (glob($artifactDir . '/*') ?: [] as $file) @unlink($file);
        @rmdir($artifactDir);
    }

    public function testProductionProofWithoutRuntimeDatabaseIsBlockedExternal(): void
    {
        $base = dirname(__DIR__, 2);
        $artifactDir = sys_get_temp_dir() . '/sirocommerce-production-proof-' . bin2hex(random_bytes(4));
        $output = [];
        $exit = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($base . '/scripts/production-proof.php') . ' --out=' . escapeshellarg($artifactDir) . ' --db=' . escapeshellarg($artifactDir . '/missing.sqlite'), $output, $exit);
        $manifest = json_decode((string) file_get_contents($artifactDir . '/manifest.json'), true);
        self::assertSame(2, $exit, implode(PHP_EOL, $output));
        self::assertSame('BLOCKED_EXTERNAL', $manifest['status'] ?? null);
        self::assertNotEmpty($manifest['external_blockers'] ?? []);
        foreach (glob($artifactDir . '/*') ?: [] as $file) @unlink($file);
        @rmdir($artifactDir);
    }

    public function testProviderSmokeDoesNotPassWithoutConfiguredContract(): void
    {
        $names = ['PROVIDER_LIVE_SMOKE', 'PAYMENT_LIVE_SMOKE_URL', 'TAX_LIVE_SMOKE_URL', 'SHIPPING_LIVE_SMOKE_URL', 'WEBHOOK_LIVE_SMOKE_URL', 'PAYMENT_DEFAULT_PROVIDER'];
        $saved = [];
        foreach ($names as $name) { $saved[$name] = getenv($name); putenv($name . '='); }
        foreach (['PAYMENT_LIVE_SMOKE_URL', 'TAX_LIVE_SMOKE_URL', 'SHIPPING_LIVE_SMOKE_URL', 'WEBHOOK_LIVE_SMOKE_URL'] as $name) putenv($name . '=https://provider.test/contract');
        putenv('PROVIDER_LIVE_SMOKE=1');
        $output = [];
        $exit = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/provider-live-smoke.php'), $output, $exit);
        $result = json_decode(implode(PHP_EOL, $output), true);
        self::assertSame(2, $exit);
        self::assertSame('BLOCKED_EXTERNAL', $result['status'] ?? null);
        self::assertSame('Provider Operations', $result['results']['payment']['health']['owner'] ?? null);
        self::assertNotEmpty($result['results']['payment']['health']['prerequisite'] ?? null);
        self::assertNotEmpty($result['results']['payment']['health']['command'] ?? null);
        self::assertNotSame('PASS', $result['results']['payment']['status'] ?? null);
        foreach ($saved as $name => $value) $value === false ? putenv($name) : putenv($name . '=' . $value);
    }
}
