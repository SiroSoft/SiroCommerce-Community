<?php

declare(strict_types=1);

namespace App\Modules\Ai\Application;

use App\Modules\Ai\Contracts\AiSuggestionRequest;
use App\Support\CommerceScope;
use App\Support\Ids;
use Siro\Core\Database;

/**
 * Merchant copilot review queue (spec LLM_GATEWAY_SPEC §copilots). Every
 * generation lands as a pending row; nothing it produces is applied
 * automatically — an admin approves or rejects it.
 *
 * Tasks: product_description, review_draft, catalog_enrichment.
 */
final class AiCopilotService
{
    public const TASKS = ['product_description', 'review_draft', 'catalog_enrichment'];
    public const STATES = ['pending', 'approved', 'rejected', 'failed'];

    public function __construct(private readonly AiSuggestionService $suggestions) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function enqueue(string $task, array $input, CommerceScope $scope, string $actorId, ?string $productId = null, int $maxCostMinor = 0): array
    {
        if (!in_array($task, self::TASKS, true)) throw new \InvalidArgumentException('Unsupported copilot task');
        $tenant = $scope->tenantId ?? '';
        if ($tenant === '') throw new \InvalidArgumentException('AI copilots require an explicit tenant scope');
        $productId = $productId !== null && $productId !== '' ? $productId : null;
        $result = $this->suggestions->suggest(new AiSuggestionRequest($task, $input, $scope, $actorId, $maxCostMinor));
        $available = $result->status === 'available';
        $row = [
            'id' => Ids::new(),
            'tenant_id' => substr($tenant, 0, 64),
            'task' => $task,
            'product_id' => $productId,
            'state' => $available ? 'pending' : 'failed',
            'input_json' => $this->encode($input),
            'output_json' => $available ? $this->encode($result->suggestion) : null,
            'provider' => isset($result->usage['provider']) && is_string($result->usage['provider']) ? $result->usage['provider'] : null,
            'model' => isset($result->usage['model']) && is_string($result->usage['model']) ? $result->usage['model'] : null,
            'reason' => $available ? null : substr((string) ($result->reason ?? $result->status), 0, 255),
            'actor_id' => substr($actorId, 0, 64),
            'reviewed_by' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'reviewed_at' => null,
        ];
        Database::connection()->prepare('INSERT INTO ai_review_queue (id, tenant_id, task, product_id, state, input_json, output_json, provider, model, reason, actor_id, reviewed_by, created_at, reviewed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$row['id'], $row['tenant_id'], $row['task'], $row['product_id'], $row['state'], $row['input_json'], $row['output_json'], $row['provider'], $row['model'], $row['reason'], $row['actor_id'], $row['reviewed_by'], $row['created_at'], $row['reviewed_at']]);
        return $this->shape($row);
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $state = null, ?string $productId = null): array
    {
        try {
            $pdo = Database::connection();
            $sql = 'SELECT id, tenant_id, task, product_id, state, output_json, provider, model, reason, actor_id, reviewed_by, created_at, reviewed_at FROM ai_review_queue WHERE 1 = 1';
            $params = [];
            if ($state !== null && $state !== '') {
                $sql .= ' AND state = ?';
                $params[] = $state;
            }
            if ($productId !== null && $productId !== '') {
                $sql .= ' AND product_id = ?';
                $params[] = $productId;
            }
            $stmt = $pdo->prepare($sql . ' ORDER BY created_at DESC LIMIT 100');
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (!is_array($rows)) return [];
            return array_map(fn (array $row): array => $this->shape($row), array_values(array_filter($rows, 'is_array')));
        } catch (\Throwable) {
            return [];
        }
    }

    /** Approve only a pending row; returns the updated row or null. */
    public function approve(string $id, string $actorId): ?array
    {
        return $this->review($id, 'approved', $actorId);
    }

    public function reject(string $id, string $actorId): ?array
    {
        return $this->review($id, 'rejected', $actorId);
    }

    /** @return array<string, mixed>|null */
    private function review(string $id, string $state, string $actorId): ?array
    {
        if ($id === '' || strlen($id) > 64) return null;
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare("UPDATE ai_review_queue SET state = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ? AND state = 'pending'");
            $stmt->execute([$state, substr($actorId, 0, 64), date('Y-m-d H:i:s'), $id]);
            if ($stmt->rowCount() !== 1) return null;
            $read = $pdo->prepare('SELECT id, tenant_id, task, product_id, state, output_json, provider, model, reason, actor_id, reviewed_by, created_at, reviewed_at FROM ai_review_queue WHERE id = ? LIMIT 1');
            $read->execute([$id]);
            $row = $read->fetch(\PDO::FETCH_ASSOC);
            return is_array($row) ? $this->shape($row) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function shape(array $row): array
    {
        $output = isset($row['output_json']) && is_string($row['output_json']) ? json_decode($row['output_json'], true) : null;
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'task' => (string) ($row['task'] ?? ''),
            'product_id' => $row['product_id'] ?? null,
            'state' => (string) ($row['state'] ?? ''),
            'suggestion' => is_array($output) ? $output : null,
            'provider' => $row['provider'] ?? null,
            'model' => $row['model'] ?? null,
            'reason' => $row['reason'] ?? null,
            'reviewed_by' => $row['reviewed_by'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'reviewed_at' => $row['reviewed_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        return substr((string) json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 8192);
    }
}
