<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Application;

use App\Modules\Promotion\Contracts\GiftStoreCreditContract;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Database;

/**
 * Gift cards + store credit on one idempotent ledger (spec: shared money loop).
 * - issue: create funded credit (admin, gift-product purchase, refund-to-credit)
 * - redeem: decrement by code (bearer) with optional owner binding
 * - rollback: reverse a keyed movement (failed checkout)
 * - expire: close a credit; remaining value is forfeited and auditable
 */
final class GiftCreditService implements GiftStoreCreditContract
{
    public const MAX_CODES = 5;

    /**
     * Normalize a split-tender code set (spec SPLIT_PAYMENTS_SPEC).
     * Legacy single code and the codes array are mutually exclusive; dupes
     * (case-insensitive) and over-limit sets are rejected fail-closed.
     * @param mixed $many string|string[]|null (repeated ?gift= or body array)
     * @return list<string> upper-cased codes in tender order
     */
    public static function normalizeCodes(?string $single, mixed $many): array
    {
        $list = [];
        if (is_string($many)) $many = explode(',', $many);
        if (is_array($many)) foreach ($many as $code) {
            if (!is_string($code) || trim($code) === '') throw new \InvalidArgumentException('Gift code is invalid');
            $list[] = strtoupper(trim($code));
        }
        if ($single !== null && trim($single) !== '') {
            if ($list !== []) throw new \InvalidArgumentException('Use gift_code or gift_codes, not both');
            $list[] = strtoupper(trim($single));
        }
        if (count($list) !== count(array_unique($list))) throw new \InvalidArgumentException('Duplicate gift code');
        if (count($list) > self::MAX_CODES) throw new \InvalidArgumentException('Too many gift codes (max ' . self::MAX_CODES . ')');
        return array_values($list);
    }

    /** @return array<string, mixed> */
    public function issue(string $ownerId, int $amountMinor, string $currency, string $idempotencyKey): array
    {
        return $this->issueCredit(null, $ownerId !== '' ? $ownerId : null, 'gift', $amountMinor, $currency, $idempotencyKey, null, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function issueCredit(?string $code, ?string $ownerId, string $type, int $amountMinor, string $currency, string $idempotencyKey, ?string $expiresAt = null, ?string $orderId = null): array
    {
        if (!in_array($type, ['gift', 'store_credit'], true)) throw new \InvalidArgumentException('Credit type is invalid');
        if ($amountMinor <= 0 || $amountMinor > 999999999) throw new \InvalidArgumentException('Amount is invalid');
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) throw new \InvalidArgumentException('Currency is invalid');
        $this->requireKey($idempotencyKey);
        $code = $code !== null && trim($code) !== '' ? strtoupper(trim($code)) : $this->newCode();
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{2,31}$/', $code)) throw new \InvalidArgumentException('Code is invalid');
        if ($expiresAt !== null && strtotime($expiresAt) === false) throw new \InvalidArgumentException('Expiry is invalid');
        $pdo = Database::connection();
        $replay = $this->byKey($idempotencyKey);
        if ($replay !== null) return $this->creditForLedger($replay);
        $id = Ids::new();
        $now = date('Y-m-d H:i:s');
        try {
            $pdo->prepare('INSERT INTO gift_credits (id, code, owner_customer_id, type, amount_minor, balance_minor, currency, state, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$id, $code, $ownerId, $type, $amountMinor, $amountMinor, $currency, 'active', $expiresAt, $now]);
        } catch (\Throwable $e) {
            $existing = $this->byCode($code);
            if ($existing !== null) throw new \InvalidArgumentException('Code is taken');
            throw $e;
        }
        $this->append($pdo, $id, $orderId, $amountMinor, $amountMinor, 'issue', $idempotencyKey, $now);
        return $this->shape($id, $code, $ownerId, $type, $amountMinor, $amountMinor, $currency, 'active', $expiresAt);
    }

    /** @return array<string, mixed> */
    public function redeem(string $creditId, string $ownerId, int $amountMinor, string $currency, string $idempotencyKey): array
    {
        return $this->redeemCode($creditId, $ownerId !== '' ? $ownerId : null, $amountMinor, $currency, $idempotencyKey, null);
    }

    /**
     * Redeem by code (bearer) or id; optional owner must match a bound credit.
     * @return array<string, mixed>
     */
    public function redeemCode(string $codeOrId, ?string $ownerId, int $amountMinor, string $currency, string $idempotencyKey, ?string $orderId): array
    {
        if ($amountMinor <= 0) throw new \InvalidArgumentException('Amount is invalid');
        $currency = strtoupper(trim($currency));
        $this->requireKey($idempotencyKey);
        $replay = $this->byKey($idempotencyKey);
        if ($replay !== null) return $this->creditForLedger($replay);
        $pdo = Database::connection();
        $credit = $this->byCode($codeOrId) ?? $this->byId($codeOrId);
        if ($credit === null) throw new \RuntimeException('Credit not found');
        if (($credit['state'] ?? '') !== 'active') throw new \RuntimeException('Credit is not active');
        if (strtoupper((string) ($credit['currency'] ?? '')) !== $currency) throw new \RuntimeException('Credit currency mismatch');
        if (isset($credit['expires_at']) && is_string($credit['expires_at']) && $credit['expires_at'] !== '' && strtotime($credit['expires_at']) < time()) {
            $this->mark($pdo, (string) $credit['id'], 'expired');
            throw new \RuntimeException('Credit has expired');
        }
        $bound = (string) ($credit['owner_customer_id'] ?? '');
        if ($bound !== '' && ($ownerId === null || $ownerId === '' || $bound !== $ownerId)) throw new \RuntimeException('Credit belongs to another customer');
        $balance = (int) ($credit['balance_minor'] ?? 0);
        if ($amountMinor > $balance) throw new \RuntimeException('Insufficient credit balance');
        $newBalance = $balance - $amountMinor;
        $now = date('Y-m-d H:i:s');
        $update = $pdo->prepare('UPDATE gift_credits SET balance_minor = ?, state = ? WHERE id = ? AND balance_minor = ?');
        $update->execute([$newBalance, $newBalance === 0 ? 'exhausted' : 'active', (string) $credit['id'], $balance]);
        if ($update->rowCount() === 0) {
            $retry = $this->byKey($idempotencyKey);
            if ($retry !== null) return $this->creditForLedger($retry);
            throw new \RuntimeException('Concurrent redemption, retry safely');
        }
        $this->append($pdo, (string) $credit['id'], $orderId, -$amountMinor, $newBalance, 'redeem', $idempotencyKey, $now);
        $credit['balance_minor'] = $newBalance;
        $credit['state'] = $newBalance === 0 ? 'exhausted' : 'active';
        return $credit;
    }

    /** @return array<string, mixed> */
    public function rollback(string $idempotencyKey): array
    {
        $this->requireKey($idempotencyKey);
        $pdo = Database::connection();
        $entry = $this->byKey($idempotencyKey);
        if ($entry === null) throw new \RuntimeException('Nothing to roll back');
        $creditId = (string) ($entry['credit_id'] ?? '');
        $reverseKey = 'rollback:' . $idempotencyKey;
        $existing = $this->byKey($reverseKey);
        if ($existing !== null) return $this->creditForLedger($existing);
        $credit = $this->byId($creditId);
        if ($credit === null) throw new \RuntimeException('Credit not found');
        $delta = -(int) ($entry['delta_minor'] ?? 0);
        $newBalance = (int) ($credit['balance_minor'] ?? 0) + $delta;
        if ($newBalance < 0) throw new \RuntimeException('Rollback would overdraw');
        $now = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE gift_credits SET balance_minor = ?, state = ? WHERE id = ?')->execute([$newBalance, 'active', $creditId]);
        $this->append($pdo, $creditId, isset($entry['order_id']) && is_string($entry['order_id']) ? $entry['order_id'] : null, $delta, $newBalance, 'rollback', $reverseKey, $now);
        $credit['balance_minor'] = $newBalance;
        $credit['state'] = 'active';
        return $credit;
    }

    /** @return array<string, mixed> */
    public function expire(string $creditId, string $idempotencyKey): array
    {
        $this->requireKey($idempotencyKey);
        $credit = $this->byId($creditId);
        if ($credit === null) throw new \RuntimeException('Credit not found');
        if (($credit['state'] ?? '') === 'expired') return $credit;
        $this->mark(Database::connection(), $creditId, 'expired');
        $credit['state'] = 'expired';
        return $credit;
    }

    /** @return array<string, mixed> */
    public function void(string $creditId): array
    {
        $credit = $this->byId($creditId);
        if ($credit === null) throw new \RuntimeException('Credit not found');
        if (!in_array($credit['state'] ?? '', ['active', 'expired'], true)) throw new \InvalidArgumentException('Only active credit can be voided');
        $this->mark(Database::connection(), $creditId, 'voided');
        $credit['state'] = 'voided';
        return $credit;
    }

    /** @return array<string, mixed>|null */
    public function findByKey(string $key): ?array
    {
        $entry = $this->byKey($key);
        return $entry === null ? null : $this->creditForLedger($entry);
    }

    /** @return array<string, mixed>|null */
    public function balance(string $codeOrId, ?string $ownerId = null): ?array
    {
        $credit = $this->byCode($codeOrId) ?? $this->byId($codeOrId);
        if ($credit === null) return null;
        $bound = (string) ($credit['owner_customer_id'] ?? '');
        if ($bound !== '' && ($ownerId === null || $ownerId === '' || $bound !== $ownerId)) return null;
        return $credit;
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $ownerId = null, ?string $type = null, int $limit = 200): array
    {
        $pdo = Database::connection();
        $where = [];
        $params = [];
        if ($ownerId !== null && $ownerId !== '') {
            $where[] = 'owner_customer_id = ?';
            $params[] = $ownerId;
        }
        if ($type !== null && $type !== '') {
            $where[] = 'type = ?';
            $params[] = $type;
        }
        $sql = 'SELECT id, code, owner_customer_id, type, amount_minor, balance_minor, currency, state, expires_at, created_at FROM gift_credits';
        if ($where !== []) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY created_at DESC LIMIT ' . min(500, max(1, $limit));
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return array<string, mixed>|null */
    private function byCode(string $code): ?array
    {
        try {
            $stmt = Database::connection()->prepare('SELECT * FROM gift_credits WHERE code = ? LIMIT 1');
            $stmt->execute([strtoupper(trim($code))]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function byId(string $id): ?array
    {
        try {
            $stmt = Database::connection()->prepare('SELECT * FROM gift_credits WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function byKey(string $key): ?array
    {
        try {
            $stmt = Database::connection()->prepare('SELECT * FROM gift_credit_ledger WHERE idempotency_key = ? LIMIT 1');
            $stmt->execute([$key]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function creditForLedger(array $entry): array
    {
        $credit = $this->byId((string) ($entry['credit_id'] ?? ''));
        if ($credit === null) throw new \RuntimeException('Credit not found');
        return $credit;
    }

    private function append(\PDO $pdo, string $creditId, ?string $orderId, int $delta, int $balanceAfter, string $reason, string $key, string $now): void
    {
        $pdo->prepare('INSERT INTO gift_credit_ledger (id, credit_id, order_id, delta_minor, balance_after_minor, reason, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([Ids::new(), $creditId, $orderId, $delta, $balanceAfter, $reason, $key, $now]);
    }

    private function mark(\PDO $pdo, string $creditId, string $state): void
    {
        $pdo->prepare('UPDATE gift_credits SET state = ? WHERE id = ?')->execute([$state, $creditId]);
    }

    private function requireKey(string $key): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) throw new \InvalidArgumentException('A valid Idempotency-Key is required');
    }

    private function newCode(): string
    {
        return 'GC-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 4) . '-' . bin2hex(random_bytes(3)));
    }

    /** @return array<string, mixed> */
    private function shape(string $id, string $code, ?string $ownerId, string $type, int $amount, int $balance, string $currency, string $state, ?string $expiresAt): array
    {
        return ['id' => $id, 'code' => $code, 'owner_customer_id' => $ownerId, 'type' => $type, 'amount_minor' => $amount, 'balance_minor' => $balance, 'currency' => $currency, 'state' => $state, 'expires_at' => $expiresAt];
    }
}
