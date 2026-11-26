<?php

declare(strict_types=1);

/**
 * Backup artifact gate. It deliberately verifies the exact artifact supplied
 * by release automation; it never treats a missing backup as success.
 * Usage: php scripts/verify-backup.php --file=/path/backup.sql --sha256=...
 */
$file = '';
$expected = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--file=')) {
        $file = substr($arg, 7);
    }
    if (str_starts_with($arg, '--sha256=')) {
        $expected = strtolower(substr($arg, 9));
    }
}

if ($file === '' || $expected === '' || !preg_match('/^[a-f0-9]{64}$/', $expected)) {
    fwrite(STDERR, "usage: php scripts/verify-backup.php --file=/path/backup --sha256=64_hex_chars\n");
    exit(2);
}
if (!is_file($file) || filesize($file) === 0) {
    fwrite(STDERR, "backup verification: missing or empty artifact\n");
    exit(1);
}

$actual = hash_file('sha256', $file);
if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
    fwrite(STDERR, "backup verification: checksum mismatch\n");
    exit(1);
}

echo 'backup verification: PASS bytes=' . filesize($file) . " sha256={$actual}\n";
