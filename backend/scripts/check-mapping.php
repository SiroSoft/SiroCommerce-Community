<?php

declare(strict_types=1);

// Mapping gate (spec docs/11§3): routes ↔ controllers ↔ lang keys.
// - every route controller class must exist under app/
// - every Lang::get('commerce.*') key must exist in storage/lang/en/commerce.php
// Usage: php scripts/check-mapping.php (exit 0 = pass)

$base = dirname(__DIR__);
$fail = 0;

$routes = file_get_contents($base . '/routes/api.php');
preg_match_all('/^use\s+(App\\\\[A-Za-z0-9_\\\\]+);/m', $routes, $uses);
$importMap = [];
foreach ($uses[1] as $fqcn) {
    $parts = explode('\\', $fqcn);
    $importMap[end($parts)] = $fqcn;
}
preg_match_all('/\[([A-Za-z0-9_\\\\]+)::class/', $routes, $m);
$classes = [];
foreach (array_unique($m[1]) as $ref) {
    if (str_contains($ref, '\\')) {
        $classes[] = $ref;
    } elseif (isset($importMap[$ref])) {
        $classes[] = $importMap[$ref];
    } else {
        echo "UNRESOLVED controller ref {$ref}\n";
        $fail = 1;
    }
}
foreach ($classes as $class) {
    $path = $base . '/' . str_replace('\\', '/', $class) . '.php';
    $path = str_replace($base . '/App/', $base . '/app/', $path);
    if (!is_file($path)) {
        echo "MISSING controller {$class}\n";
        $fail = 1;
    }
}

/** @var array<string, mixed> $en */
$en = require $base . '/storage/lang/en/commerce.php';
$flat = static function (array $a, string $p = '') use (&$flat): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : "{$p}.{$k}";
        $o += is_array($v) ? $flat($v, $key) : [$key => $v];
    }
    return $o;
};
$keys = $flat($en);

$allowedSetters = ['setCartState', 'setState'];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . '/app'));
foreach ($files as $file) {
    if ($file->isDir() || $file->getExtension() !== 'php') {
        continue;
    }
    $pathname = $file->getPathname();
    $src = file_get_contents($pathname);
    if (!is_string($src)) {
        echo "UNREADABLE {$pathname}\n";
        $fail = 1;
        continue;
    }
    preg_match_all("/Lang::get\('commerce\.([^']+)'/", $src, $mm);
    foreach ($mm[1] as $used) {
        if (!array_key_exists($used, $keys)) {
            echo "UNKNOWN lang key commerce.{$used} in {$file->getPathname()}\n";
            $fail = 1;
        }
    }
    if (preg_match_all("/update\(\s*\[\s*'state'/", $src, $hits, PREG_OFFSET_CAPTURE)) {
        // Only the two repository setters may write state; they are called
        // exclusively from services enforcing named transitions (spec 02§2).
        foreach ($hits[0] as $hit) {
            $before = substr($src, 0, $hit[1]);
            $pos = strrpos($before, 'function ');
            $enclosing = $pos !== false ? substr($before, $pos, 60) : '';
            $allowed = false;
            foreach ($allowedSetters as $setter) {
                if (str_starts_with($enclosing, "function {$setter}(")) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                echo "DIRECT state write in {$pathname} outside setCartState/setState\n";
                $fail = 1;
                break;
            }
        }
    }
}

echo $fail === 0 ? "mapping OK\n" : "mapping FAILED\n";
exit($fail);
