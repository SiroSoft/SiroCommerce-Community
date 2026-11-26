<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;
use App\Models\AnalyticsEvent;
use App\Modules\Analytics\Contracts\AnalyticsStore;
use App\Modules\Analytics\Contracts\AnalyticsReader;
use App\Modules\Analytics\Contracts\AnalyticsExporter;
use App\Support\Ids;
use Siro\Core\Database;
final class AnalyticsStoreAdapter implements AnalyticsStore
    , AnalyticsReader, AnalyticsExporter
{
    /** @return array<string, mixed> */ public function record(array $data): array
    {
        $eventIdValue = $data['event_id'] ?? $data['id'] ?? '';
        $eventId = is_string($eventIdValue) ? $eventIdValue : '';
        $existing = $eventId === '' ? null : AnalyticsEvent::query()->where('event_id', $eventId)->first();
        if ($existing !== null) return $existing->toArray();
        try {
            /** @var array<string, mixed> $out */
            $out = AnalyticsEvent::create(array_merge(['id' => Ids::new(), 'created_at' => date('Y-m-d H:i:s')], $data))->toArray();
        } catch (\PDOException $exception) {
            // A concurrent replay can lose the pre-check race but still satisfies the unique key.
            if (!in_array((string) $exception->getCode(), ['19', '23000'], true)) throw $exception;
            $existing = $eventId === '' ? null : AnalyticsEvent::query()->where('event_id', $eventId)->first();
            if ($existing === null) throw $exception;
            return $existing->toArray();
        }
        return $out;
    }
    public function count(string $event): int { $query = Database::connection()->prepare('SELECT COUNT(*) AS c FROM analytics_events WHERE event = ?'); $query->execute([$event]); $row = $query->fetch(); $value = is_array($row) ? ($row['c'] ?? 0) : 0; return is_numeric($value) ? (int) $value : 0; }
    /** @return array{data: array<int, array<string, mixed>>, meta: array<string, int>} */
    public function read(string $from, string $to, ?string $event, int $page, int $perPage, ?string $storeId = null, ?string $channelId = null): array
    {
        $where = 'created_at BETWEEN ? AND ?'; $params = [$from, $to];
        if ($event !== null && $event !== '') { $where .= ' AND event = ?'; $params[] = $event; }
        if ($storeId !== null && $storeId !== '') { $where .= ' AND store_id = ?'; $params[] = $storeId; }
        if ($channelId !== null && $channelId !== '') { $where .= ' AND channel_id = ?'; $params[] = $channelId; }
        $pdo = Database::connection();
        $count = $pdo->prepare('SELECT COUNT(*) AS c FROM analytics_events WHERE ' . $where); $count->execute($params);
        $totalRow = $count->fetch(); $total = is_array($totalRow) && is_numeric($totalRow['c'] ?? null) ? (int) $totalRow['c'] : 0;
        $offset = ($page - 1) * $perPage;
        $query = $pdo->prepare('SELECT id, event_id, event, schema_version, entity_type, entity_id, value_minor, created_at, payload_json, store_id, channel_id, locale, currency FROM analytics_events WHERE ' . $where . ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?');
        foreach ($params as $i => $param) $query->bindValue($i + 1, $param);
        $query->bindValue(count($params) + 1, $perPage, \PDO::PARAM_INT); $query->bindValue(count($params) + 2, $offset, \PDO::PARAM_INT); $query->execute();
        $rows = [];
        foreach ($query->fetchAll() ?: [] as $row) { if (!is_array($row)) continue; $payloadValue = $row['payload_json'] ?? '{}'; $payload = json_decode(is_string($payloadValue) ? $payloadValue : '{}', true); $clean = []; foreach ($row as $key => $value) if (is_string($key)) $clean[$key] = $value; $clean['payload'] = is_array($payload) ? \App\Modules\Analytics\Domain\AnalyticsRedactor::redact($payload) : []; unset($clean['payload_json']); $rows[] = $clean; }
        return ['data' => $rows, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))]];
    }
    /** @return array{schema_version: int, exported_at: string, records: array<int, array<string, mixed>>} */
    public function export(string $from, string $to, ?string $event, int $page, int $perPage): array
    {
        return ['schema_version' => \App\Modules\Analytics\Domain\AnalyticsEventSchema::VERSION, 'exported_at' => date(DATE_ATOM), 'records' => $this->read($from, $to, $event, $page, $perPage)['data']];
    }
}
