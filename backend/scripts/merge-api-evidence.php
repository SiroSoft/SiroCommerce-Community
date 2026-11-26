<?php

declare(strict_types=1);

/** Merge independently reviewed exact-route evidence into the handoff registry. */
$base = dirname(__DIR__);
$directory = $base . '/tests/api-handoff/evidence';
$merged = [];
$files = glob($directory . '/*.json') ?: [];
sort($files);

foreach ($files as $file) {
    $document = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $operations = $document['operations'] ?? $document['routes'] ?? null;
    if (!is_array($operations) && $document !== []) {
        $firstKey = (string) array_key_first($document);
        if (preg_match('/^(GET|POST|PUT|PATCH|DELETE)\s+/', $firstKey) === 1) {
            $operations = $document;
        }
    }
    if (!is_array($operations) && isset($document['unit'], $document['integration'], $document['api_component'], $document['contract'], $document['workflow'])) {
        $operations = [];
        foreach (['unit', 'integration', 'api_component', 'contract', 'workflow'] as $layer) {
            foreach (($document[$layer]['operations'] ?? []) as $operation => $record) {
                $operations[$operation][$layer] = $record;
            }
        }
    }
    if (!is_array($operations)) {
        throw new RuntimeException('Evidence file has no exact operation map: ' . basename($file));
    }

    foreach ($operations as $operation => $layers) {
        if (!is_array($layers)) {
            throw new RuntimeException("Invalid evidence map for {$operation}");
        }
        foreach (['unit', 'integration', 'api_component', 'contract', 'workflow'] as $layer) {
            $record = $layers[$layer] ?? null;
            if (!is_array($record) || !isset($record['status'])) {
                throw new RuntimeException("{$operation}: missing {$layer} evidence");
            }
            $normalized = $record;
            if (!isset($normalized['evidence']) && isset($normalized['reference'])) {
                $normalized['evidence'] = $normalized['reference'];
                unset($normalized['reference']);
            }
            if (is_array($normalized['evidence'] ?? null)) {
                $normalized['evidence'] = implode('; ', array_map(static fn (mixed $value): string => (string) $value, $normalized['evidence']));
            }
            if ($normalized['status'] === 'PASS' && trim((string) ($normalized['evidence'] ?? '')) === '') {
                throw new RuntimeException("{$operation}: {$layer} PASS has no evidence");
            }
            if (isset($merged[$operation][$layer])) {
                $existing = $merged[$operation][$layer];
                if ($existing['status'] !== $normalized['status']) {
                    throw new RuntimeException("Conflicting status for {$operation} {$layer}");
                }
                $references = array_filter([(string) ($existing['evidence'] ?? ''), (string) ($normalized['evidence'] ?? '')]);
                $normalized['evidence'] = implode('; ', array_values(array_unique($references)));
            }
            $merged[$operation][$layer] = $normalized;
        }
    }
}

ksort($merged);
$output = $base . '/tests/api-handoff/coverage-overrides.json';
file_put_contents($output, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
echo 'api-handoff: merged ' . count($merged) . " exact operations from " . count($files) . " evidence files\n";
