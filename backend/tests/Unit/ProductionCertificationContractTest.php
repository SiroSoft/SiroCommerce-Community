<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductionCertificationContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $items = [];
        foreach (['production-proof', 'provider-preflight', 'provider-live-smoke', 'backup-restore', 'backup-infrastructure', 'reconciliation'] as $id) {
            $items[] = ['id' => $id, 'status' => 'BLOCKED_EXTERNAL', 'requirement' => 'required evidence', 'owner' => 'Operations', 'reason' => 'external prerequisite unavailable', 'evidence' => []];
        }
        $items[] = ['id' => 'config-static', 'status' => 'PASS', 'requirement' => 'static checks', 'owner' => 'Release Operations', 'evidence' => ['check-production-proof.php']];
        $now = gmdate('c');
        return ['certification_version' => 1, 'status' => 'BLOCKED_EXTERNAL', 'generated_at' => $now, 'release' => ['run_id' => 'cert-test', 'commit' => 'abcdef1234567', 'actor' => 'test'], 'freshness' => ['max_age_seconds' => 86400, 'as_of' => $now], 'redaction' => ['applied' => true, 'policy' => 'references only', 'secrets_included' => false], 'checklist' => $items];
    }

    /** @param array<string, mixed> $manifest */
    private function check(array $manifest): int
    {
        $path = tempnam(sys_get_temp_dir(), 'certification-');
        self::assertIsString($path);
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
        $script = dirname(__DIR__, 2) . '/scripts/check-certification.php';
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --manifest=' . escapeshellarg($path), $output, $exit);
        unlink($path);
        return $exit;
    }

    public function testValidBlockedManifestPassesContract(): void { self::assertSame(0, $this->check($this->manifest())); }

    public function testMissingControlFailsContract(): void
    {
        $manifest = $this->manifest(); array_pop($manifest['checklist']);
        self::assertSame(1, $this->check($manifest));
    }

    public function testSecretValueFailsContract(): void
    {
        $manifest = $this->manifest(); $manifest['notes'] = 'Bearer do-not-store';
        self::assertSame(1, $this->check($manifest));
    }

    public function testBlockedItemNeedsReason(): void
    {
        $manifest = $this->manifest(); unset($manifest['checklist'][0]['reason']);
        self::assertSame(1, $this->check($manifest));
    }
}
