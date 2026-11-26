<?php

declare(strict_types=1);

namespace App\Extensions\Siro\SizeGuide;

use App\Modules\Extension\Application\ExtensionScope;
use App\Support\CommerceScope;
use App\Support\Ids;
use Siro\Core\Database;

/**
 * Store/channel scoped category size charts. One chart per (store_id,
 * channel_id, category_slug); every read and write filters ExtensionScope so a
 * scope can never observe or overwrite another scope's chart.
 */
final class SizeGuideService
{
    public const MAX_CATEGORY = 160;
    public const MAX_TITLE = 120;
    public const MIN_COLUMNS = 1;
    public const MAX_COLUMNS = 8;
    public const MIN_ROWS = 1;
    public const MAX_ROWS = 50;
    public const MAX_COLUMN_NAME = 60;
    public const MAX_CELL = 200;
    public const MAX_NOTE = 300;

    private const COLUMNS = 'id, store_id, channel_id, category_slug, title, columns_json, rows_json, note, active, created_at, updated_at';

    /**
     * Charts visible in the request scope, ordered by category slug.
     *
     * @return list<array<string, mixed>>
     */
    public function adminList(CommerceScope $scope): array
    {
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        return array_map(
            fn (array $row): array => $this->present($row),
            $this->rows(
                'SELECT ' . self::COLUMNS . ' FROM ext_size_guide_charts WHERE ' . $where
                . ' ORDER BY category_slug ASC, id ASC',
                $params
            )
        );
    }

    /**
     * Active chart for one category in the request scope, or null when the
     * category has no active chart. The public projection never exposes
     * scope ids or moderation state.
     *
     * @return array<string, mixed>|null
     */
    public function publicChart(CommerceScope $scope, mixed $category): ?array
    {
        $slug = $this->normalizeCategory($category);
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $row = $this->first(
            'SELECT ' . self::COLUMNS . ' FROM ext_size_guide_charts WHERE ' . $where
            . ' AND category_slug = ? AND active = 1 LIMIT 1',
            array_merge($params, [$slug])
        );
        return $row === null ? null : $this->publicRow($row);
    }

    /**
     * Idempotent upsert for the category in the request scope. Replaying the
     * same body returns the same canonical row; a concurrent insert race falls
     * back to an update of the winning row.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function upsert(CommerceScope $scope, mixed $category, array $data): array
    {
        $slug = $this->normalizeCategory($category);
        $existing = $this->find($scope, $slug);
        $chart = $this->normalizeChart($data, $existing);
        $now = date('Y-m-d H:i:s');

        if ($existing !== null) {
            return $this->update($scope, $existing, $slug, $chart, $now);
        }

        $row = ExtensionScope::merge([
            'id' => Ids::new(),
            'category_slug' => $slug,
            'title' => $chart['title'],
            'columns_json' => json_encode($chart['columns'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'rows_json' => json_encode($chart['rows'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'note' => $chart['note'],
            'active' => $chart['active'] ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $scope);

        try {
            Database::table('ext_size_guide_charts')->insert($row);
        } catch (\Throwable $exception) {
            $winner = $this->find($scope, $slug);
            if ($winner !== null) {
                return $this->update($scope, $winner, $slug, $chart, $now);
            }
            throw new \RuntimeException('Size guide chart could not be saved', 0, $exception);
        }

        $saved = $this->find($scope, $slug);
        if ($saved === null) {
            throw new \RuntimeException('Size guide chart could not be saved');
        }
        return $this->present($saved);
    }

    /**
     * @param array<string, mixed> $existing
     * @param array{title: string, columns: list<string>, rows: list<list<string>>, note: ?string, active: bool} $chart
     * @return array<string, mixed>
     */
    private function update(CommerceScope $scope, array $existing, string $slug, array $chart, string $now): array
    {
        $id = $this->stringValue($existing['id'] ?? null);
        if ($id === '') {
            throw new \RuntimeException('Size guide chart could not be saved');
        }
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $stmt = Database::connection()->prepare(
            'UPDATE ext_size_guide_charts SET category_slug = ?, title = ?, columns_json = ?, rows_json = ?, note = ?, active = ?, updated_at = ? WHERE '
            . $where . ' AND id = ?'
        );
        if (!$stmt instanceof \PDOStatement) {
            throw new \RuntimeException('Size guide chart could not be saved');
        }
        $stmt->execute(array_merge([
            $slug,
            $chart['title'],
            json_encode($chart['columns'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($chart['rows'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $chart['note'],
            $chart['active'] ? 1 : 0,
            $now,
        ], $params, [$id]));

        $saved = $this->find($scope, $slug);
        if ($saved === null) {
            throw new \RuntimeException('Size guide chart could not be saved');
        }
        return $this->present($saved);
    }

    /** @return array<string, mixed>|null */
    private function find(CommerceScope $scope, string $slug): ?array
    {
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        return $this->first(
            'SELECT ' . self::COLUMNS . ' FROM ext_size_guide_charts WHERE ' . $where . ' AND category_slug = ? LIMIT 1',
            array_merge($params, [$slug])
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $existing
     * @return array{title: string, columns: list<string>, rows: list<list<string>>, note: ?string, active: bool}
     */
    private function normalizeChart(array $data, ?array $existing): array
    {
        $title = isset($data['title']) && is_string($data['title']) ? trim($data['title']) : '';
        $titleLength = mb_strlen($title);
        if ($titleLength < 1 || $titleLength > self::MAX_TITLE) {
            throw new \InvalidArgumentException('title must be between 1 and ' . self::MAX_TITLE . ' characters');
        }
        $this->assertPlainText($title, 'title');

        $columns = $this->normalizeColumns($data['columns'] ?? null);
        $rows = $this->normalizeRows($data['rows'] ?? null, count($columns));
        $note = $this->normalizeNote($data['note'] ?? null);

        $active = $existing !== null ? ($this->boolValue($existing['active'] ?? null) ?? true) : true;
        if (array_key_exists('active', $data)) {
            $active = $this->normalizeActive($data['active']);
        }

        return [
            'title' => $title,
            'columns' => $columns,
            'rows' => $rows,
            'note' => $note,
            'active' => $active,
        ];
    }

    /** @return list<string> */
    private function normalizeColumns(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('columns must be a list of column names');
        }
        $count = count($value);
        if ($count < self::MIN_COLUMNS || $count > self::MAX_COLUMNS) {
            throw new \InvalidArgumentException('columns must contain between ' . self::MIN_COLUMNS . ' and ' . self::MAX_COLUMNS . ' names');
        }

        $columns = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException('columns must contain only strings');
            }
            $name = trim($item);
            $length = mb_strlen($name);
            if ($length < 1 || $length > self::MAX_COLUMN_NAME) {
                throw new \InvalidArgumentException('each column name must be between 1 and ' . self::MAX_COLUMN_NAME . ' characters');
            }
            $this->assertPlainText($name, 'columns');
            $columns[] = $name;
        }
        return $columns;
    }

    /** @return list<list<string>> */
    private function normalizeRows(mixed $value, int $columnCount): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('rows must be a list of arrays');
        }
        $count = count($value);
        if ($count < self::MIN_ROWS || $count > self::MAX_ROWS) {
            throw new \InvalidArgumentException('rows must contain between ' . self::MIN_ROWS . ' and ' . self::MAX_ROWS . ' entries');
        }

        $rows = [];
        foreach ($value as $row) {
            if (!is_array($row) || !array_is_list($row)) {
                throw new \InvalidArgumentException('each row must be an array of cells');
            }
            if (count($row) !== $columnCount) {
                throw new \InvalidArgumentException('each row must have exactly ' . $columnCount . ' cells to match the columns');
            }
            $cells = [];
            foreach ($row as $cell) {
                if ($cell === null) {
                    $cells[] = '';
                    continue;
                }
                if (!is_scalar($cell)) {
                    throw new \InvalidArgumentException('row cells must be strings or numbers');
                }
                $text = is_bool($cell) ? ($cell ? '1' : '0') : trim((string) $cell);
                if (mb_strlen($text) > self::MAX_CELL) {
                    throw new \InvalidArgumentException('row cells must be at most ' . self::MAX_CELL . ' characters');
                }
                $this->assertPlainText($text, 'rows');
                $cells[] = $text;
            }
            $rows[] = $cells;
        }
        return $rows;
    }

    private function normalizeNote(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('note must be a string');
        }
        $note = trim($value);
        if ($note === '') {
            return null;
        }
        if (mb_strlen($note) > self::MAX_NOTE) {
            throw new \InvalidArgumentException('note must be at most ' . self::MAX_NOTE . ' characters');
        }
        $this->assertPlainText($note, 'note');
        return $note;
    }

    private function normalizeCategory(mixed $value): string
    {
        $slug = is_string($value) ? trim($value) : '';
        if ($slug === '') {
            throw new \InvalidArgumentException('category is required');
        }
        if (mb_strlen($slug) > self::MAX_CATEGORY) {
            throw new \InvalidArgumentException('category must be at most ' . self::MAX_CATEGORY . ' characters');
        }
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            throw new \InvalidArgumentException('category must be a lowercase slug of letters, digits and hyphens');
        }
        return $slug;
    }

    private function normalizeActive(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false'], true)) {
                return false;
            }
        }
        throw new \InvalidArgumentException('active must be a boolean');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $category = $this->stringValue($row['category_slug'] ?? null);
        return [
            'id' => $this->stringValue($row['id'] ?? null),
            'store_id' => $this->stringValue($row['store_id'] ?? null),
            'channel_id' => $this->stringValue($row['channel_id'] ?? null),
            'category' => $category,
            'category_slug' => $category,
            'title' => $this->stringValue($row['title'] ?? null),
            'columns' => $this->decodeColumns($row['columns_json'] ?? null),
            'rows' => $this->decodeRows($row['rows_json'] ?? null),
            'note' => $this->nullableString($row['note'] ?? null),
            'active' => $this->boolValue($row['active'] ?? null) ?? false,
            'created_at' => $this->nullableString($row['created_at'] ?? null),
            'updated_at' => $this->nullableString($row['updated_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicRow(array $row): array
    {
        $present = $this->present($row);
        return [
            'category' => $present['category'],
            'category_slug' => $present['category_slug'],
            'title' => $present['title'],
            'columns' => $present['columns'],
            'rows' => $present['rows'],
            'note' => $present['note'],
        ];
    }

    /** @return list<string> */
    private function decodeColumns(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return [];
        }
        $columns = [];
        foreach ($decoded as $item) {
            if (is_string($item)) {
                $columns[] = $item;
            }
        }
        return $columns;
    }

    /** @return list<list<string>> */
    private function decodeRows(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return [];
        }
        $rows = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || !array_is_list($row)) {
                continue;
            }
            $cells = [];
            foreach ($row as $cell) {
                if (is_bool($cell)) {
                    $cells[] = $cell ? '1' : '0';
                } elseif (is_scalar($cell)) {
                    $cells[] = (string) $cell;
                }
            }
            $rows[] = $cells;
        }
        return $rows;
    }

    private function assertPlainText(string $value, string $field): void
    {
        if (preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            throw new \InvalidArgumentException($field . ' must not contain HTML or control characters');
        }
        if (preg_match('/javascript\s*:/i', $value) === 1) {
            throw new \InvalidArgumentException($field . ' must not contain scripts');
        }
    }

    private function boolValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower($value);
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }
        return null;
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return is_scalar($value) ? trim((string) $value) : null;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $stmt = Database::connection()->prepare($sql);
        if (!$stmt instanceof \PDOStatement) {
            return [];
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $clean = [];
                foreach ($row as $key => $value) {
                    if (is_string($key)) {
                        $clean[$key] = $value;
                    }
                }
                $result[] = $clean;
            }
        }
        return $result;
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    private function first(string $sql, array $params = []): ?array
    {
        $rows = $this->rows($sql, $params);
        return $rows[0] ?? null;
    }
}
