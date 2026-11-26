<?php

declare(strict_types=1);

// Parity gate: every locale must expose the exact flattened key set of `en`.
// Usage: php scripts/check-i18n.php (exit 0 = pass, exit 1 = missing/extra keys)

$base = dirname(__DIR__) . '/storage/lang';
$locales = ['en', 'vi', 'ja', 'de', 'zh'];

$flatten = static function (array $arr, string $prefix = '') use (&$flatten): array {
    $out = [];
    foreach ($arr as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix . '.' . (string) $k;
        if (is_array($v)) {
            $out += $flatten($v, $key);
        } else {
            $out[$key] = $v;
        }
    }
    return $out;
};

$fail = 0;
$reference = [];
foreach (glob($base . '/en/*.php') ?: [] as $file) {
    $group = basename($file, '.php');
    /** @var array<string, mixed> $data */
    $data = require $file;
    $reference[$group] = array_keys($flatten($data));
}

foreach ($locales as $locale) {
    foreach ($reference as $group => $keys) {
        $file = "{$base}/{$locale}/{$group}.php";
        if (!is_file($file)) {
            echo "MISSING file {$locale}/{$group}.php\n";
            $fail = 1;
            continue;
        }
        /** @var array<string, mixed> $data */
        $data = require $file;
        $have = $flatten($data);
        foreach ($keys as $k) {
            if (!array_key_exists($k, $have) || $have[$k] === '') {
                echo "MISSING key {$locale}/{$group}.{$k}\n";
                $fail = 1;
            }
        }
        foreach (array_keys($have) as $k) {
            if (!in_array($k, $keys, true)) {
                echo "EXTRA key {$locale}/{$group}.{$k}\n";
                $fail = 1;
            }
        }
    }
}

echo $fail === 0 ? "i18n parity OK (5 locales)\n" : "i18n parity FAILED\n";
exit($fail);
