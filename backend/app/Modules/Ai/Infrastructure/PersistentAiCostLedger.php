<?php

declare(strict_types=1);

namespace App\Modules\Ai\Infrastructure;

use App\Modules\Ai\Contracts\AiCostLedger;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * Per-tenant AI budget ledger (spec LLM_GATEWAY_SPEC). First use seeds the
 * limit from AI_BUDGET_MINOR (default 0 = closed until funded). Reserve is a
 * single atomic UPDATE so concurrent requests cannot overspend.
 */
final class PersistentAiCostLedger implements AiCostLedger
{
    public function remainingMinor(string $tenantId): int
    {
        if ($tenantId === '') return 0;
        try {
            $this->seed($tenantId);
            $stmt = Database::connection()->prepare('SELECT limit_minor, spent_minor FROM ai_budgets WHERE tenant_id = ? LIMIT 1');
            $stmt->execute([$tenantId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)) return 0;
            return max(0, (int) ($row['limit_minor'] ?? 0) - (int) ($row['spent_minor'] ?? 0));
        } catch (\Throwable) {
            return 0;
        }
    }

    public function reserveMinor(string $tenantId, int $amountMinor): bool
    {
        if ($tenantId === '' || $amountMinor < 0) return false;
        try {
            $this->seed($tenantId);
            if ($amountMinor === 0) {
                // A zero-cost reservation is a no-op in SQL. It is allowed only
                // when the tenant has a funded budget; default 0 stays closed.
                $stmt = Database::connection()->prepare('SELECT limit_minor FROM ai_budgets WHERE tenant_id = ? LIMIT 1');
                $stmt->execute([$tenantId]);
                return (int) $stmt->fetchColumn() > 0;
            }
            $stmt = Database::connection()->prepare('UPDATE ai_budgets SET spent_minor = spent_minor + ?, updated_at = ? WHERE tenant_id = ? AND spent_minor + ? <= limit_minor');
            $stmt->execute([$amountMinor, date('Y-m-d H:i:s'), $tenantId, $amountMinor]);
            return $stmt->rowCount() === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function seed(string $tenantId): void
    {
        $pdo = Database::connection();
        $exists = $pdo->prepare('SELECT tenant_id FROM ai_budgets WHERE tenant_id = ? LIMIT 1');
        $exists->execute([$tenantId]);
        if ($exists->fetchColumn() !== false) return;
        $limit = max(0, (int) Env::get('AI_BUDGET_MINOR', '0'));
        $isMysql = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $sql = $isMysql
            ? 'INSERT INTO ai_budgets (tenant_id, limit_minor, spent_minor, updated_at) VALUES (?, ?, 0, ?) ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id)'
            : 'INSERT INTO ai_budgets (tenant_id, limit_minor, spent_minor, updated_at) VALUES (?, ?, 0, ?) ON CONFLICT(tenant_id) DO NOTHING';
        $pdo->prepare($sql)->execute([$tenantId, $limit, date('Y-m-d H:i:s')]);
    }
}
