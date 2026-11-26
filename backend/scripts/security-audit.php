<?php

declare(strict_types=1);

/** Static release gate for accidental secret exposure and unsafe debug code. */
$root = dirname(__DIR__);
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app'));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $files[] = $file->getPathname();
    }
}

$findings = [];
foreach ($files as $file) {
    $contents = file_get_contents($file);
    if ($contents === false) {
        $findings[] = $file . ': unreadable';
        continue;
    }
    $patterns = [
        '/\b(var_dump|print_r|var_export|debug_zval_dump|dd)\s*\(/' => 'debug output',
        '/\b(eval|shell_exec|passthru)\s*\(/' => 'unsafe process/eval call',
        '/(?:echo|print|error_log|logger\s*->\s*\w+)\s*\([^;]*(?:password|secret|token|cvv|card)/i' => 'possible secret logging',
    ];
    foreach ($patterns as $pattern => $label) {
        if (preg_match($pattern, $contents) === 1) {
            $findings[] = str_replace($root . DIRECTORY_SEPARATOR, '', $file) . ': ' . $label;
        }
    }
    if (str_ends_with(str_replace('\\', '/', $file), '/app/Console/Commands/CommerceCheckCommand.php')
        && preg_match('/proc_open\(\$command\s*,/', $contents) !== 1) {
        $findings[] = str_replace($root . DIRECTORY_SEPARATOR, '', $file) . ': command runner must use array proc_open';
    }
}

if ($findings !== []) {
    fwrite(STDERR, "security-audit: FAIL\n" . implode("\n", $findings) . "\n");
    exit(1);
}

echo 'security-audit: PASS (' . count($files) . " application files)\n";
