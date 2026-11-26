<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Application;
use App\Modules\Catalog\Contracts\CatalogImportStore;
use App\Support\Ids;
use App\Support\CommerceScope;
use App\Models\Product;
use App\Support\Arr;

final class CatalogTransferService
{
    public const SCHEMA_VERSION = 1;
    private const MAX_ROWS = 1000;
    private const FIELDS = ['name', 'slug', 'status', 'type', 'store_id', 'channel_id', 'locale', 'currency', 'description', 'tags_json'];

    public function __construct(
        private readonly CatalogCompletenessService $catalog,
        private readonly CatalogImportStore $imports,
    ) {}

    /** @return array<string, mixed> */
    public function import(string $format, string $source, bool $validateOnly = false, ?CommerceScope $scope = null): array
    {
        if (!in_array($format, ['jsonl', 'csv'], true)) throw new \InvalidArgumentException('Catalog format must be jsonl or csv');
        if ($source === '') throw new \InvalidArgumentException('Catalog source is required');
        $fingerprint = hash('sha256', $format . "\0" . $source);
        if (!$validateOnly) {
            $existing = $this->imports->findByFingerprint($fingerprint);
            if ($existing !== null) {
                return $this->result($existing, true);
            }
        }
        /** @var array<int, array{row: int, errors: array<int, string>}> $errors */
        $errors = [];
        $seen = 0;
        $imported = 0;
        foreach ($this->rows($format, $source) as $number => $row) {
            $seen = $number;
            $rowErrors = $this->validateRow($row);
            if ($rowErrors !== []) { $errors[] = ['row' => $number, 'errors' => $rowErrors]; continue; }
            if (!$validateOnly) {
                $outcome = $this->catalog->bulkImport([$row], $scope);
                $imported += $outcome['imported'];
                foreach ($outcome['errors'] as $error) $errors[] = ['row' => $number, 'errors' => $error['errors']];
            }
        }
        $result = ['schema_version' => self::SCHEMA_VERSION, 'format' => $format, 'source_fingerprint' => $fingerprint, 'validate_only' => $validateOnly, 'rows_seen' => $seen, 'rows_imported' => $imported, 'errors' => $errors, 'replayed' => false];
        if (!$validateOnly) {
            $now = date('Y-m-d H:i:s');
            $this->imports->create(['id' => Ids::new(), 'source_fingerprint' => $fingerprint, 'format' => $format, 'schema_version' => self::SCHEMA_VERSION, 'state' => 'completed', 'rows_seen' => $seen, 'rows_imported' => $imported, 'errors_json' => json_encode($errors, JSON_THROW_ON_ERROR), 'created_at' => $now, 'completed_at' => $now]);
        }
        return $result;
    }

    /** Export is deliberately generated from the native catalog read model, never from a search provider. */
    public function export(string $format, ?CommerceScope $scope = null): string
    {
        if (!in_array($format, ['jsonl', 'csv'], true)) throw new \InvalidArgumentException('Catalog format must be jsonl or csv');
        $query = Product::query()->whereNull('archived_at')->orderBy('id');
        if ($scope !== null) $query->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)->where('locale', $scope->locale)->where('currency', $scope->currency);
        $fields = ['name', 'slug', 'status', 'type', 'store_id', 'channel_id', 'locale', 'currency', 'description', 'tags_json'];
        $rows = array_map(static fn (mixed $model): array => array_intersect_key(Arr::row($model), array_flip($fields)), $query->get());
        if ($format === 'jsonl') return implode("\n", array_map(static fn (array $row): string => json_encode(['schema_version' => self::SCHEMA_VERSION, 'record' => $row], JSON_THROW_ON_ERROR), $rows)) . ($rows === [] ? '' : "\n");
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) throw new \RuntimeException('Unable to create catalog export');
        fputcsv($stream, $fields);
        foreach ($rows as $row) fputcsv($stream, array_map(static fn (string $field): mixed => $row[$field] ?? null, $fields));
        rewind($stream); $contents = stream_get_contents($stream); fclose($stream);
        return $contents === false ? '' : $contents;
    }

    /** @return iterable<int, array<string, mixed>> */
    private function rows(string $format, string $source): iterable
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) throw new \RuntimeException('Unable to open catalog source');
        fwrite($stream, $source); rewind($stream);
        if ($format === 'csv') {
            $header = fgetcsv($stream);
            if (!is_array($header)) return;
            $header = array_map(static fn (mixed $value): string => trim((string) $value), $header);
            $number = 1;
            while (($values = fgetcsv($stream)) !== false) {
                $number++;
                if ($number > self::MAX_ROWS + 1) throw new \InvalidArgumentException('Catalog source exceeds 1000 rows');
                $row = []; foreach ($header as $index => $key) $row[$key] = $values[$index] ?? null;
                yield $number - 1 => $row;
            }
            return;
        }
        $number = 0;
        while (($line = fgets($stream)) !== false) {
            $number++; if ($number > self::MAX_ROWS) throw new \InvalidArgumentException('Catalog source exceeds 1000 rows');
            $decoded = json_decode(trim($line), true);
            if (!is_array($decoded)) yield $number => ['_invalid' => 'JSONL row must be an object'];
            elseif (isset($decoded['schema_version']) && is_numeric($decoded['schema_version']) && (int) $decoded['schema_version'] !== self::SCHEMA_VERSION) yield $number => ['_invalid' => 'Unsupported catalog schema version'];
            else {
                $value = isset($decoded['record']) && is_array($decoded['record']) ? $decoded['record'] : $decoded;
                $row = [];
                foreach ($value as $key => $item) if (is_string($key)) $row[$key] = $item;
                yield $number => $row;
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, string>
     */
    private function validateRow(array $row): array
    {
        if (isset($row['_invalid']) && is_string($row['_invalid'])) return [$row['_invalid']];
        $errors = [];
        $name = $row['name'] ?? null;
        if (!is_string($name) || trim($name) === '') $errors[] = 'name is required';
        foreach (['slug', 'status', 'type', 'store_id', 'channel_id', 'locale', 'currency', 'description', 'tags_json'] as $field) if (isset($row[$field]) && !is_string($row[$field])) $errors[] = $field . ' must be a string';
        foreach (array_keys($row) as $key) if (!in_array($key, self::FIELDS, true)) $errors[] = 'unsupported field: ' . $key;
        return array_values(array_unique($errors));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function result(array $row, bool $replayed): array
    {
        $errors = json_decode(is_string($row['errors_json'] ?? null) ? $row['errors_json'] : '[]', true);
        return ['schema_version' => is_numeric($row['schema_version'] ?? null) ? (int) $row['schema_version'] : 0, 'format' => is_string($row['format'] ?? null) ? $row['format'] : '', 'source_fingerprint' => is_string($row['source_fingerprint'] ?? null) ? $row['source_fingerprint'] : '', 'validate_only' => false, 'rows_seen' => is_numeric($row['rows_seen'] ?? null) ? (int) $row['rows_seen'] : 0, 'rows_imported' => is_numeric($row['rows_imported'] ?? null) ? (int) $row['rows_imported'] : 0, 'errors' => is_array($errors) ? $errors : [], 'replayed' => $replayed];
    }
}
