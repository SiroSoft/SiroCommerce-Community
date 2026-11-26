<?php

declare(strict_types=1);

/**
 * Deterministic local algorithm benchmark. It is not production capacity proof.
 * Usage: php scripts/benchmark-local.php --dataset=10000 --iterations=25
 */
$dataset = 10000;
$iterations = 25;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--dataset=')) $dataset = (int) substr($arg, 10);
    if (str_starts_with($arg, '--iterations=')) $iterations = (int) substr($arg, 13);
}
if (!in_array($dataset, [10000, 50000, 100000], true) || $iterations < 1 || $iterations > 100) {
    fwrite(STDERR, "dataset must be 10000, 50000, or 100000 and iterations must be 1..100\n");
    exit(2);
}
$rows = [];
for ($i = 0; $i < $dataset; $i++) $rows[] = ['id' => $i, 'active' => ($i % 5) !== 0, 'created_at' => $dataset - $i];
$times = [];
for ($run = 0; $run < $iterations; $run++) {
    $started = hrtime(true);
    $active = array_values(array_filter($rows, static fn (array $row): bool => $row['active']));
    usort($active, static fn (array $a, array $b): int => ($b['created_at'] <=> $a['created_at']) ?: ($b['id'] <=> $a['id']));
    array_slice($active, 499, 100);
    $times[] = (hrtime(true) - $started) / 1_000_000;
}
sort($times);
$percentile = static fn (float $fraction): float => $times[min(count($times) - 1, (int) ceil(count($times) * $fraction) - 1)];
$result = ['status' => 'PASS', 'evidence_boundary' => 'Synthetic in-process algorithm only; no database, network, or production capacity claim.', 'dataset' => $dataset, 'iterations' => $iterations, 'p50_ms' => round($percentile(.50), 3), 'p95_ms' => round($percentile(.95), 3), 'p99_ms' => round($percentile(.99), 3)];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
