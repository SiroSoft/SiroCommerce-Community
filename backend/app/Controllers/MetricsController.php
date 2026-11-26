<?php

declare(strict_types=1);

namespace App\Controllers;

use Siro\Core\Controller;
use Siro\Core\Env;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Prometheus scrape endpoint backed by the persisted metrics snapshot.
 *
 * The web process keeps counters/histograms in memory and flushes them to
 * storage/framework/metrics.json (see MetricsPersistCommand). This controller
 * never touches the live collector: it renders that on-disk snapshot in the
 * Prometheus text exposition format (version=0.0.4), so a scrape cannot block
 * request handling and every instance reports a consistent point-in-time view.
 *
 * Access model:
 * - METRICS_TOKEN set: require `Authorization: Bearer <token>` or
 *   `X-Metrics-Token: <token>` (401 missing, 403 wrong).
 * - METRICS_TOKEN unset: only non-production may scrape; production returns
 *   404 so an unconfigured deployment cannot leak operational data.
 * Admin auth is deliberately not used: Prometheus scrapes with a static token.
 */
final class MetricsController extends Controller
{
    private const CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    public function index(Request $request): Response
    {
        $token = trim((string) Env::get('METRICS_TOKEN', ''));
        $environment = strtolower(trim((string) Env::get('APP_ENV', 'production')));

        if ($token !== '') {
            $presented = $this->presentedToken($request);
            if ($presented === '') {
                return Response::raw("Unauthorized\n", self::CONTENT_TYPE, 401);
            }
            if (!hash_equals($token, $presented)) {
                return Response::raw("Forbidden\n", self::CONTENT_TYPE, 403);
            }
        } elseif ($environment === 'production') {
            return Response::raw('', self::CONTENT_TYPE, 404);
        }

        return Response::raw($this->render($this->snapshot()), self::CONTENT_TYPE, 200);
    }

    private function presentedToken(Request $request): string
    {
        $authorization = trim((string) $request->header('authorization', ''));
        if (preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches) === 1) {
            return $matches[1];
        }

        return trim((string) $request->header('x-metrics-token', ''));
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $base = defined('BASE_PATH') && is_string(BASE_PATH)
            ? BASE_PATH
            : (defined('SIRO_BASE_PATH') && is_string(SIRO_BASE_PATH) ? SIRO_BASE_PATH : dirname(__DIR__, 2));
        $file = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
            . 'framework' . DIRECTORY_SEPARATOR . 'metrics.json';

        if (!is_file($file)) {
            return [];
        }

        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        $snapshot = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $snapshot[$key] = $value;
            }
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    private function render(array $snapshot): string
    {
        $lines = [];
        $this->renderCounters($snapshot['counters'] ?? null, $lines);
        $this->renderHistograms($snapshot['histograms'] ?? null, $lines);
        $this->renderGauges($snapshot['gauges'] ?? null, $lines);

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /** @param array<int, string> $lines */
    private function renderCounters(mixed $counters, array &$lines): void
    {
        if (!is_array($counters)) {
            return;
        }

        /** @var array<string, array{help: string, samples: array<int, string>}> $groups */
        $groups = [];
        foreach ($counters as $key => $entry) {
            if (!is_string($key) || !is_array($entry)) {
                continue;
            }
            [$name, $labelsKey] = $this->splitSeriesKey($key);
            $metric = $this->metricName($name);
            if ($metric === '') {
                continue;
            }
            $labels = $this->entryLabels($entry, $labelsKey);
            if (!isset($groups[$metric])) {
                $groups[$metric] = [
                    'help' => $this->helpText($this->stringValue($entry['help'] ?? null, 'Counter ' . $name)),
                    'samples' => [],
                ];
            }
            $groups[$metric]['samples'][] = $this->sample($metric, $labels, $entry['value'] ?? 0);
        }

        ksort($groups);
        foreach ($groups as $metric => $group) {
            $lines[] = '# HELP ' . $metric . ' ' . $group['help'];
            $lines[] = '# TYPE ' . $metric . ' counter';
            foreach ($group['samples'] as $sample) {
                $lines[] = $sample;
            }
        }
    }

    /** @param array<int, string> $lines */
    private function renderHistograms(mixed $histograms, array &$lines): void
    {
        if (!is_array($histograms)) {
            return;
        }

        $byName = [];
        foreach ($histograms as $name => $data) {
            if (is_string($name) && is_array($data)) {
                $byName[$name] = $data;
            }
        }
        ksort($byName);

        foreach ($byName as $name => $data) {
            $metric = $this->metricName($name);
            if ($metric === '') {
                continue;
            }

            $buckets = $this->numericList($data['buckets'] ?? null);
            $values = $this->seriesMap($data['values'] ?? null);
            $sums = $this->seriesMap($data['sum'] ?? null);
            $counts = $this->seriesMap($data['count'] ?? null);
            $series = array_keys($values + $counts);
            if ($series === []) {
                continue;
            }
            sort($series, SORT_STRING);

            $lines[] = '# HELP ' . $metric . ' '
                . $this->helpText($this->stringValue($data['help'] ?? null, 'Histogram ' . $name));
            $lines[] = '# TYPE ' . $metric . ' histogram';

            foreach ($series as $labelsKey) {
                $labels = $this->parseLabels($labelsKey);
                $perBucket = $this->intList($values[$labelsKey] ?? null);
                $count = isset($counts[$labelsKey])
                    ? $this->intValue($counts[$labelsKey])
                    : array_sum($perBucket);
                $sum = $this->floatValue($sums[$labelsKey] ?? null);

                $cumulative = 0;
                foreach ($buckets as $index => $bound) {
                    $cumulative += $perBucket[$index] ?? 0;
                    $cumulative = min($cumulative, $count);
                    $lines[] = $metric . '_bucket'
                        . $this->labelString($labels, ['le' => $this->bound($bound)])
                        . ' ' . $cumulative;
                }
                $lines[] = $metric . '_bucket' . $this->labelString($labels, ['le' => '+Inf']) . ' ' . $count;
                $lines[] = $metric . '_sum' . $this->labelString($labels) . ' ' . $this->formatValue($sum);
                $lines[] = $metric . '_count' . $this->labelString($labels) . ' ' . $count;
            }
        }
    }

    /** @param array<int, string> $lines */
    private function renderGauges(mixed $gauges, array &$lines): void
    {
        if (!is_array($gauges)) {
            return;
        }

        /** @var array<string, array{help: string, samples: array<int, string>}> $groups */
        $groups = [];
        foreach ($gauges as $key => $entry) {
            if (!is_string($key) || !is_array($entry)) {
                continue;
            }
            [$name, $labelsKey] = $this->splitSeriesKey($key);
            $metric = $this->metricName($name);
            if ($metric === '') {
                continue;
            }
            $labels = $this->entryLabels($entry, $labelsKey);
            if (!isset($groups[$metric])) {
                $groups[$metric] = [
                    'help' => $this->helpText($this->stringValue($entry['help'] ?? null, 'Gauge ' . $name)),
                    'samples' => [],
                ];
            }
            $groups[$metric]['samples'][] = $this->sample($metric, $labels, $entry['value'] ?? 0);
        }

        ksort($groups);
        foreach ($groups as $metric => $group) {
            $lines[] = '# HELP ' . $metric . ' ' . $group['help'];
            $lines[] = '# TYPE ' . $metric . ' gauge';
            foreach ($group['samples'] as $sample) {
                $lines[] = $sample;
            }
        }
    }

    /** @return array{0: string, 1: string} */
    private function splitSeriesKey(string $key): array
    {
        $position = strpos($key, '::');

        return $position === false
            ? [$key, '']
            : [substr($key, 0, $position), substr($key, $position + 2)];
    }

    /**
     * @param array<mixed, mixed> $entry
     * @return array<string, string>
     */
    private function entryLabels(array $entry, string $fallbackKey): array
    {
        $labelsData = $entry['labels'] ?? null;
        if (is_array($labelsData)) {
            $labels = [];
            foreach ($labelsData as $name => $value) {
                if (is_string($name) && is_scalar($value)) {
                    $labels[$name] = (string) $value;
                }
            }

            return $labels;
        }

        return $this->parseLabels($fallbackKey);
    }

    /** @return array<string, string> */
    private function parseLabels(string $key): array
    {
        if ($key === '') {
            return [];
        }

        $labels = [];
        $parts = preg_split('/,(?=[A-Za-z_][A-Za-z0-9_]*=)/', $key);
        foreach (is_array($parts) ? $parts : [] as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) === 2) {
                $labels[$pair[0]] = $pair[1];
            }
        }

        return $labels;
    }

    /** @return array<string, mixed> */
    private function seriesMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $map[$key] = $item;
            }
        }

        return $map;
    }

    /** @return array<int, int> */
    private function intList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            $list[] = $this->intValue($item);
        }

        return $list;
    }

    /** @return array<int, float> */
    private function numericList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            $list[] = $this->floatValue($item);
        }

        return $list;
    }

    private function stringValue(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    private function intValue(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? (int) $value : $default;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $default;
    }

    private function floatValue(mixed $value, float $default = 0.0): float
    {
        if (is_float($value)) {
            return is_finite($value) ? $value : $default;
        }
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        return $default;
    }

    private function metricName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        $sanitized = preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? '';
        $sanitized = trim($sanitized, '_');
        if ($sanitized === '') {
            return '';
        }
        if (preg_match('/^[0-9]/', $sanitized) === 1) {
            $sanitized = '_' . $sanitized;
        }

        return str_starts_with($sanitized, 'siro_') ? $sanitized : 'siro_' . $sanitized;
    }

    private function helpText(string $help): string
    {
        $help = trim($help);
        if ($help === '') {
            return 'SiroCommerce metric';
        }

        return str_replace(['\\', "\n", "\r"], ['\\\\', '\\n', '\\n'], $help);
    }

    /**
     * @param array<string, string> $labels
     * @param array<string, string> $extra
     */
    private function labelString(array $labels, array $extra = []): string
    {
        ksort($labels);

        $parts = [];
        foreach ($labels as $name => $value) {
            $this->appendLabel($parts, $name, $value);
        }
        foreach ($extra as $name => $value) {
            $this->appendLabel($parts, $name, $value);
        }

        return $parts === [] ? '' : '{' . implode(',', $parts) . '}';
    }

    /** @param array<int, string> $parts */
    private function appendLabel(array &$parts, string $name, string $value): void
    {
        $safeName = preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? '';
        if ($safeName === '') {
            return;
        }
        if (preg_match('/^[0-9]/', $safeName) === 1) {
            $safeName = '_' . $safeName;
        }
        if (str_starts_with($safeName, '__')) {
            return;
        }
        $parts[] = $safeName . '="' . $this->labelValue($value) . '"';
    }

    private function labelValue(string $value): string
    {
        return str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\n'], $value);
    }

    /** @param array<string, string> $labels */
    private function sample(string $metric, array $labels, mixed $value): string
    {
        return $metric . $this->labelString($labels) . ' ' . $this->formatValue($value);
    }

    private function formatValue(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (!is_float($value) && !is_string($value) && !is_bool($value)) {
            return '0';
        }

        $float = (float) $value;
        if (!is_finite($float)) {
            return '0';
        }
        if ($float === floor($float) && abs($float) < 1.0e15) {
            return (string) (int) $float;
        }

        $formatted = rtrim(rtrim(sprintf('%.6F', $float), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function bound(float $bound): string
    {
        return $this->formatValue($bound);
    }
}
