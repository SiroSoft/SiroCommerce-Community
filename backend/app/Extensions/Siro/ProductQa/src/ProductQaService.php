<?php

declare(strict_types=1);

namespace App\Extensions\Siro\ProductQa;

use App\Modules\Extension\Application\ExtensionScope;
use App\Support\CommerceScope;
use App\Support\Ids;
use Siro\Core\Database;

/**
 * Store/channel scoped product Q&A. Every read and write goes through
 * ExtensionScope so one store/channel can never observe another's questions.
 * Submissions are pending until an admin approves/rejects/answers them.
 */
final class ProductQaService
{
    /** @var list<string> */
    public const STATES = ['pending', 'approved', 'rejected'];

    public const MAX_PRODUCT_ID = 64;
    public const MAX_PRODUCT_SLUG = 160;
    public const MIN_NAME = 2;
    public const MAX_NAME = 80;
    public const MIN_QUESTION = 5;
    public const MAX_QUESTION = 1000;
    public const MAX_ANSWER = 2000;
    public const MAX_IDEMPOTENCY_KEY = 128;
    public const MAX_LIST = 100;

    private const COLUMNS = 'id, store_id, channel_id, product_id, product_slug, customer_name, question, answer, state, idempotency_key, created_at, answered_at';

    /**
     * Approved questions for one product in the request scope.
     *
     * @return list<array<string, mixed>>
     */
    public function listApproved(CommerceScope $scope, string $productId, int $limit = self::MAX_LIST): array
    {
        $productId = $this->cleanProductId($productId);
        $limit = max(1, min(self::MAX_LIST, $limit));
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        return $this->rows(
            'SELECT ' . self::COLUMNS . ' FROM ext_product_qa WHERE ' . $where
            . ' AND product_id = ? AND state = ? ORDER BY answered_at DESC, created_at DESC, id DESC LIMIT ' . $limit,
            array_merge($params, [$productId, 'approved'])
        );
    }

    /**
     * Create a pending question. A replay with the same scoped Idempotency-Key
     * returns the canonical row instead of duplicating it.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function submit(CommerceScope $scope, array $data, ?string $idempotencyKey = null): array
    {
        $normalized = $this->normalizeSubmit($data);
        $key = $this->cleanIdempotencyKey($idempotencyKey);

        if ($key !== null) {
            $existing = $this->findByIdempotencyKey($scope, $key);
            if ($existing !== null) {
                return $existing;
            }
        }

        $now = date('Y-m-d H:i:s');
        $row = ExtensionScope::merge([
            'id' => Ids::new(),
            'product_id' => $normalized['product_id'],
            'product_slug' => $normalized['product_slug'],
            'customer_name' => $normalized['customer_name'],
            'question' => $normalized['question'],
            'answer' => null,
            'state' => 'pending',
            'idempotency_key' => $key,
            'created_at' => $now,
            'answered_at' => null,
        ], $scope);

        try {
            Database::table('ext_product_qa')->insert($row);
        } catch (\Throwable $exception) {
            $winner = $key !== null ? $this->findByIdempotencyKey($scope, $key) : null;
            if ($winner !== null) {
                return $winner;
            }
            throw new \RuntimeException('Product question could not be saved', 0, $exception);
        }

        return $row;
    }

    /**
     * Admin list. `$state` accepts pending|approved|rejected|all.
     *
     * @return list<array<string, mixed>>
     */
    public function adminList(CommerceScope $scope, string $state = 'pending', ?string $productId = null): array
    {
        $state = strtolower(trim($state));
        if ($state === '') {
            $state = 'pending';
        }
        if ($state !== 'all' && !in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException('state must be pending, approved, rejected or all');
        }
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ext_product_qa WHERE ' . $where;
        $bind = $params;
        if ($state !== 'all') {
            $sql .= ' AND state = ?';
            $bind[] = $state;
        }
        $productId = $productId !== null ? trim($productId) : '';
        if ($productId !== '') {
            $sql .= ' AND product_id = ?';
            $bind[] = $this->cleanProductId($productId);
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . self::MAX_LIST;
        return $this->rows($sql, $bind);
    }

    /**
     * Approve, reject or answer a question. Returns null when the id is not
     * visible in the request scope. Repeated identical updates are idempotent.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function adminUpdate(CommerceScope $scope, string $id, array $data): ?array
    {
        $id = trim($id);
        if ($id === '') {
            throw new \InvalidArgumentException('Question id is required');
        }
        $existing = $this->find($scope, $id);
        if ($existing === null) {
            return null;
        }

        $hasState = array_key_exists('state', $data) && $data['state'] !== null && $data['state'] !== '';
        $hasAnswer = array_key_exists('answer', $data) && $data['answer'] !== null;
        if (!$hasState && !$hasAnswer) {
            throw new \InvalidArgumentException('state or answer is required');
        }

        $state = is_string($existing['state'] ?? null) ? $existing['state'] : 'pending';
        $answer = is_string($existing['answer'] ?? null) ? $existing['answer'] : null;

        if ($hasState) {
            $candidate = is_string($data['state']) ? strtolower(trim($data['state'])) : '';
            $candidate = match ($candidate) {
                'approve', 'approved' => 'approved',
                'reject', 'rejected' => 'rejected',
                default => '',
            };
            if ($candidate === '') {
                throw new \InvalidArgumentException('state must be approved or rejected');
            }
            $state = $candidate;
        }
        if ($hasAnswer) {
            $answer = $this->cleanAnswer($data['answer']);
            if (!$hasState) {
                $state = 'approved';
            }
        }
        if ($state === ($existing['state'] ?? null) && $answer === ($existing['answer'] ?? null)) {
            return $existing;
        }

        $answeredAt = is_string($existing['answered_at'] ?? null) && $existing['answered_at'] !== ''
            ? $existing['answered_at']
            : date('Y-m-d H:i:s');

        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $stmt = Database::connection()->prepare(
            'UPDATE ext_product_qa SET state = ?, answer = ?, answered_at = ? WHERE ' . $where . ' AND id = ?'
        );
        if (!$stmt instanceof \PDOStatement) {
            throw new \RuntimeException('Product question could not be updated');
        }
        $stmt->execute(array_merge([$state, $answer, $answeredAt], $params, [$id]));

        $updated = $this->find($scope, $id);
        if ($updated === null) {
            throw new \RuntimeException('Product question could not be updated');
        }
        return $updated;
    }

    /** @return array<string, mixed>|null */
    private function find(CommerceScope $scope, string $id): ?array
    {
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        return $this->first(
            'SELECT ' . self::COLUMNS . ' FROM ext_product_qa WHERE ' . $where . ' AND id = ? LIMIT 1',
            array_merge($params, [$id])
        );
    }

    /** @return array<string, mixed>|null */
    private function findByIdempotencyKey(CommerceScope $scope, string $key): ?array
    {
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        return $this->first(
            'SELECT ' . self::COLUMNS . ' FROM ext_product_qa WHERE ' . $where . ' AND idempotency_key = ? LIMIT 1',
            array_merge($params, [$key])
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array{product_id: string, product_slug: ?string, customer_name: string, question: string}
     */
    private function normalizeSubmit(array $data): array
    {
        $productId = $this->cleanProductId(isset($data['product_id']) ? $data['product_id'] : null);
        $slug = isset($data['product_slug']) && is_string($data['product_slug']) ? trim($data['product_slug']) : '';
        if ($slug !== '') {
            $this->assertPlainText($slug, 'product_slug');
            if (mb_strlen($slug) > self::MAX_PRODUCT_SLUG) {
                throw new \InvalidArgumentException('product_slug must be at most ' . self::MAX_PRODUCT_SLUG . ' characters');
            }
        }

        $name = isset($data['customer_name']) && is_string($data['customer_name']) ? trim($data['customer_name']) : '';
        $this->assertPlainText($name, 'customer_name');
        $nameLength = mb_strlen($name);
        if ($nameLength < self::MIN_NAME || $nameLength > self::MAX_NAME) {
            throw new \InvalidArgumentException('customer_name must be between ' . self::MIN_NAME . ' and ' . self::MAX_NAME . ' characters');
        }

        $question = isset($data['question']) && is_string($data['question']) ? trim($data['question']) : '';
        $this->assertPlainText($question, 'question');
        $questionLength = mb_strlen($question);
        if ($questionLength < self::MIN_QUESTION || $questionLength > self::MAX_QUESTION) {
            throw new \InvalidArgumentException('question must be between ' . self::MIN_QUESTION . ' and ' . self::MAX_QUESTION . ' characters');
        }

        return [
            'product_id' => $productId,
            'product_slug' => $slug !== '' ? $slug : null,
            'customer_name' => $name,
            'question' => $question,
        ];
    }

    private function cleanProductId(mixed $value): string
    {
        $productId = is_string($value) ? trim($value) : '';
        if ($productId === '') {
            throw new \InvalidArgumentException('product_id is required');
        }
        $this->assertPlainText($productId, 'product_id');
        if (mb_strlen($productId) > self::MAX_PRODUCT_ID) {
            throw new \InvalidArgumentException('product_id must be at most ' . self::MAX_PRODUCT_ID . ' characters');
        }
        return $productId;
    }

    private function cleanAnswer(mixed $value): string
    {
        $answer = is_string($value) ? trim($value) : '';
        if ($answer === '') {
            throw new \InvalidArgumentException('answer cannot be empty');
        }
        $this->assertPlainText($answer, 'answer');
        if (mb_strlen($answer) > self::MAX_ANSWER) {
            throw new \InvalidArgumentException('answer must be at most ' . self::MAX_ANSWER . ' characters');
        }
        return $answer;
    }

    private function cleanIdempotencyKey(?string $key): ?string
    {
        $key = $key !== null ? trim($key) : '';
        if ($key === '') {
            return null;
        }
        if (strlen($key) > self::MAX_IDEMPOTENCY_KEY || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) {
            throw new \InvalidArgumentException('Idempotency-Key is invalid');
        }
        return $key;
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
