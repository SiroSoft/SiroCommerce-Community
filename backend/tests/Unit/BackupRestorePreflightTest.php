<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BackupRestorePreflightTest extends TestCase
{
    public function testPreflightFailsClosedWhenManifestChecksumDrifts(): void
    {
        $base = dirname(__DIR__, 2);
        $root = sys_get_temp_dir() . '/siro-preflight-' . bin2hex(random_bytes(4));
        mkdir($root . '/uploads', 0775, true);
        file_put_contents($root . '/database.sqlite', 'database');
        file_put_contents($root . '/uploads/a.txt', 'upload');
        $manifest = [
            'created_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'database' => ['path' => $root . '/database.sqlite', 'bytes' => 8, 'sha256' => str_repeat('0', 64)],
            'uploads' => ['path' => $root . '/uploads', 'files' => [['path' => 'a.txt', 'bytes' => 6, 'sha256' => hash('sha256', 'upload')]],],
        ];
        file_put_contents($root . '/backup.json', json_encode($manifest));
        $output = [];
        $exit = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($base . '/scripts/backup-restore-preflight.php') . ' --manifest=' . escapeshellarg($root . '/backup.json') . ' --out=' . escapeshellarg($root . '/evidence'), $output, $exit);
        self::assertSame(1, $exit);
        self::assertStringContainsString('FAIL', implode(PHP_EOL, $output));
        $evidence = json_decode((string) file_get_contents($root . '/evidence/manifest.json'), true);
        self::assertSame('FAIL', $evidence['status'] ?? null);
        self::assertSame('FAIL', $evidence['database_restore']['status'] ?? null);
        self::assertSame('BLOCKED_EXTERNAL', $evidence['external_blockers'][0]['status'] ?? null);
        self::assertStringContainsString('migration rehearsal skipped', $evidence['external_blockers'][0]['reason'] ?? '');
    }
}
