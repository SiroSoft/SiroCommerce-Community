<?php

declare(strict_types=1);

namespace App\Extensions\Siro\AbandonedCart;

use App\Models\Cart;
use App\Models\Customer;
use App\Modules\Consent\Application\ConsentService;
use App\Modules\Coupon\Application\CouponService;
use App\Modules\Extension\Application\ExtensionScope;
use App\Modules\Mail\Application\MailTemplateRegistry;
use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\Ids;
use Siro\Core\Container;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * Free Abandoned Cart (siro/abandoned-cart).
 *
 * Listens to the platform `cart.abandoned` event (Batch 1 §2.3): the
 * scheduler already gated eligibility (consent, 7-day frequency cap,
 * purchase-suppressed) before emitting. This listener persists the totals
 * snapshot, re-checks marketing consent through the public contract, and
 * sends exactly one reminder email with a deterministic recovery coupon.
 *
 * Scope note: core `carts` rows carry no store_id/channel_id, so snapshots
 * are recorded under the platform default scope (store `default`, channel
 * `web` — the same fallback CartService uses). Admin reads use the explicit
 * request scope; a snapshot is only visible under that default scope until
 * core adds scope columns to carts.
 *
 * The listener never throws: a failure here must not break the core
 * scheduler flow (RULE-E10 row 6).
 */
final class AbandonedCartService
{
    public const SCOPE_STORE_ID = 'default';
    public const SCOPE_CHANNEL_ID = 'web';

    public const SIGNAL = 'abandoned_cart';
    public const STEP = 1;

    public const COUPON_PERCENT_OFF = 10;
    public const COUPON_VALID_DAYS = 7;
    public const COUPON_USAGE_LIMIT = 1;

    public function __construct(
        private readonly ?ConsentService $consent = null,
        private readonly ?CouponService $coupons = null,
    ) {
    }

    public static function storefrontCartUrl(): string
    {
        $url = trim((string) (Env::get('STOREFRONT_URL', '') !== '' ? Env::get('STOREFRONT_URL', '') : Env::get('APP_URL', 'https://ecommerce.sirophp.com')));
        return (rtrim($url, '/') ?: 'https://ecommerce.sirophp.com') . '/cart';
    }

    /** Deterministic recovery coupon code for a cart (stable across replays). */
    public static function recoveryCouponCode(string $cartId): string
    {
        return 'AC-RECOVER-' . strtoupper(substr(md5($cartId), 0, 8));
    }

    /** @return array{store_id: string, channel_id: string} */
    public static function defaultScope(): array
    {
        return ['store_id' => self::SCOPE_STORE_ID, 'channel_id' => self::SCOPE_CHANNEL_ID];
    }

    /**
     * Handle one `cart.abandoned` payload. Never throws.
     * @param array<string, mixed> $payload
     */
    public function onCartAbandoned(array $payload): void
    {
        try {
            $this->handleAbandoned($payload);
        } catch (\Throwable $exception) {
            error_log('[abandoned-cart] listener failed: ' . $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $payload */
    private function handleAbandoned(array $payload): void
    {
        $cartId = Arr::str($payload, 'cart_id');
        if ($cartId === '') return;
        $customerId = Arr::nullableStr($payload, 'customer_id');
        $totals = Arr::row($payload['totals_minor'] ?? []);
        $at = Arr::str($payload, 'at');
        if ($at === '' || strtotime($at) === false) $at = date('Y-m-d H:i:s');

        $this->saveSnapshot($cartId, $customerId, $totals, $at);

        // No identity, no consent check, no mail — the snapshot is still kept.
        if ($customerId === null || $customerId === '') return;
        if (!$this->hasMarketingConsent($customerId)) return;
        $customer = Customer::find($customerId);
        if ($customer === null) return;
        $row = Arr::row($customer);
        $email = Arr::str($row, 'email');
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) return;

        $key = self::idempotencyKey($cartId);
        if ($this->deliveryExists($key)) return;

        $couponCode = $this->ensureRecoveryCoupon($cartId);
        $lines = $totals['lines'] ?? [];
        $sent = MailTemplateRegistry::send('cart.abandoned', $email, [
            'customer_name' => Arr::str($row, 'name'),
            'cart_url' => self::storefrontCartUrl(),
            'lines' => is_array($lines) ? array_values($lines) : [],
            'coupon_code' => $couponCode,
        ]);
        if ($sent) $this->recordDelivery($cartId, $key);
    }

    public static function idempotencyKey(string $cartId): string
    {
        return self::SIGNAL . ':' . $cartId . ':step-' . self::STEP;
    }

    /**
     * Create (or reuse) a recovery coupon for a cart. Returns the code, or
     * '' when coupon creation is unavailable — the reminder is still sent
     * without a coupon rather than failing the whole flow.
     */
    public function ensureRecoveryCoupon(string $cartId): string
    {
        $code = self::recoveryCouponCode($cartId);
        try {
            $service = $this->coupons();
            foreach ($service->all() as $existing) {
                if (strtoupper(Arr::str($existing, 'code')) === $code) return $code;
            }
            $service->create([
                'code' => $code,
                'rules' => json_encode([
                    'type' => 'percent',
                    'value' => self::COUPON_PERCENT_OFF,
                    'usage_limit' => self::COUPON_USAGE_LIMIT,
                    'per_customer_limit' => 1,
                    'ends_at' => date('Y-m-d H:i:s', time() + self::COUPON_VALID_DAYS * 86400),
                ], JSON_THROW_ON_ERROR),
                'state' => 'active',
            ]);
            return $code;
        } catch (\Throwable) {
            // Duplicate-code race: the other writer won; reuse the code.
            try {
                foreach ($this->coupons()->all() as $existing) {
                    if (strtoupper(Arr::str($existing, 'code')) === $code) return $code;
                }
            } catch (\Throwable) {
            }
            return '';
        }
    }

    /**
     * Admin-created coupon (POST /api/ext/siro/abandoned-cart/coupons).
     * Validation mirrors CouponController::store(); violations throw
     * InvalidArgumentException so the controller maps them to 422.
     * Replay-safe: creating the same code twice returns the existing row.
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createCoupon(array $data): array
    {
        $code = Arr::str($data, 'code');
        if ($code === '') {
            $cartId = Arr::str($data, 'cart_id');
            $code = $cartId !== '' ? self::recoveryCouponCode($cartId) : 'AC-' . strtoupper(bin2hex(random_bytes(4)));
        }
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{2,63}\z/', $code) !== 1) {
            throw new \InvalidArgumentException('Coupon code must be 3-64 letters, numbers, hyphens, or underscores');
        }
        $rules = $data['rules'] ?? null;
        if ($rules === null) {
            $decoded = [
                'type' => 'percent',
                'value' => self::COUPON_PERCENT_OFF,
                'usage_limit' => self::COUPON_USAGE_LIMIT,
                'per_customer_limit' => 1,
                'ends_at' => date('Y-m-d H:i:s', time() + self::COUPON_VALID_DAYS * 86400),
            ];
        } elseif (is_array($rules)) {
            $decoded = Arr::row($rules);
        } elseif (is_string($rules)) {
            $parsed = json_decode($rules, true);
            if (!is_array($parsed) || (array_is_list($parsed) && $parsed !== [])) {
                throw new \InvalidArgumentException('Coupon rules must be a JSON object');
            }
            $decoded = Arr::row($parsed);
        } else {
            throw new \InvalidArgumentException('Coupon rules must be a JSON object');
        }
        $this->validateCouponRules($decoded);
        $service = $this->coupons();
        foreach ($service->all() as $existing) {
            if (strtoupper(Arr::str($existing, 'code')) === strtoupper($code)) return $existing;
        }
        try {
            return $service->create([
                'code' => $code,
                'rules' => json_encode($decoded, JSON_THROW_ON_ERROR),
                'state' => 'active',
            ]);
        } catch (\Throwable $exception) {
            foreach ($service->all() as $existing) {
                if (strtoupper(Arr::str($existing, 'code')) === strtoupper($code)) return $existing;
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $decoded */
    private function validateCouponRules(array $decoded): void
    {
        if (isset($decoded['currency']) && $decoded['currency'] !== '' && !preg_match('/^[A-Za-z]{3}$/', Arr::str($decoded, 'currency'))) {
            throw new \InvalidArgumentException('Coupon currency is invalid');
        }
        foreach (['usage_limit', 'max_uses', 'min_spend_minor', 'min_subtotal_minor', 'max_discount_minor', 'per_customer_limit', 'per_user_limit'] as $limit) {
            if (isset($decoded[$limit]) && (!is_int($decoded[$limit]) || $decoded[$limit] < 0)) {
                throw new \InvalidArgumentException("Coupon {$limit} must be a non-negative integer");
            }
        }
        if (isset($decoded['type']) && !in_array($decoded['type'], ['fixed', 'percent', 'percentage', 'buy_x_get_y'], true)) {
            throw new \InvalidArgumentException('Coupon type is unsupported');
        }
        if (isset($decoded['value']) && (!is_int($decoded['value']) || $decoded['value'] < 0 || (in_array($decoded['type'] ?? 'fixed', ['percent', 'percentage'], true) && $decoded['value'] > 100))) {
            throw new \InvalidArgumentException('Coupon value is invalid');
        }
        foreach (['starts_at', 'start_at', 'date_from', 'ends_at', 'end_at', 'date_to'] as $dateKey) {
            if (isset($decoded[$dateKey]) && $decoded[$dateKey] !== '' && strtotime(Arr::str($decoded, $dateKey)) === false) {
                throw new \InvalidArgumentException("Coupon {$dateKey} is invalid");
            }
        }
    }

    /** @param array<string, mixed> $totals */
    private function saveSnapshot(string $cartId, ?string $customerId, array $totals, string $at): void
    {
        $now = date('Y-m-d H:i:s');
        $encoded = json_encode($totals, JSON_THROW_ON_ERROR);
        $existing = Database::first(
            'SELECT id FROM ext_abandoned_cart_snapshots WHERE store_id = ? AND channel_id = ? AND cart_id = ?',
            [self::SCOPE_STORE_ID, self::SCOPE_CHANNEL_ID, $cartId]
        );
        if ($existing !== null) {
            $stmt = Database::connection()->prepare(
                'UPDATE ext_abandoned_cart_snapshots SET customer_id = ?, snapshot_json = ?, abandoned_at = ?, updated_at = ? WHERE store_id = ? AND channel_id = ? AND cart_id = ?'
            );
            if ($stmt instanceof \PDOStatement) {
                $stmt->execute([$customerId, $encoded, $at, $now, self::SCOPE_STORE_ID, self::SCOPE_CHANNEL_ID, $cartId]);
            }
            return;
        }
        try {
            Database::table('ext_abandoned_cart_snapshots')->insert([
                'id' => Ids::new(),
                'store_id' => self::SCOPE_STORE_ID,
                'channel_id' => self::SCOPE_CHANNEL_ID,
                'cart_id' => $cartId,
                'customer_id' => $customerId,
                'snapshot_json' => $encoded,
                'abandoned_at' => $at,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable) {
            // Concurrent first-write race: the winner's row stands.
        }
    }

    private function deliveryExists(string $key): bool
    {
        return Database::first(
            'SELECT id FROM ext_abandoned_cart_deliveries WHERE store_id = ? AND channel_id = ? AND idempotency_key = ?',
            [self::SCOPE_STORE_ID, self::SCOPE_CHANNEL_ID, $key]
        ) !== null;
    }

    private function recordDelivery(string $cartId, string $key): void
    {
        try {
            Database::table('ext_abandoned_cart_deliveries')->insert([
                'id' => Ids::new(),
                'store_id' => self::SCOPE_STORE_ID,
                'channel_id' => self::SCOPE_CHANNEL_ID,
                'cart_id' => $cartId,
                'signal' => self::SIGNAL,
                'step' => self::STEP,
                'sent_at' => date('Y-m-d H:i:s'),
                'idempotency_key' => $key,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Unique-key race with a concurrent send: already recorded.
        }
    }

    private function hasMarketingConsent(string $customerId): bool
    {
        try {
            $service = $this->consent();
            return $service->hasMarketingConsent($customerId);
        } catch (\Throwable) {
            // Fail closed: without a readable consent answer, send nothing.
            return false;
        }
    }

    private function consent(): ConsentService
    {
        if ($this->consent !== null) return $this->consent;
        $resolved = Container::getInstance()->make(ConsentService::class);
        if (!$resolved instanceof ConsentService) throw new \RuntimeException('Consent service is unavailable');
        return $resolved;
    }

    private function coupons(): CouponService
    {
        if ($this->coupons !== null) return $this->coupons;
        $resolved = Container::getInstance()->make(CouponService::class);
        if (!$resolved instanceof CouponService) throw new \RuntimeException('Coupon service is unavailable');
        return $resolved;
    }

    /**
     * Admin overview stats for a scope.
     * @return array{stats: list<array{label: string, value: int|string}>}
     */
    public function overview(CommerceScope $scope): array
    {
        $snapshots = $this->snapshotRows($scope);
        $sent = count($this->deliveryRows($scope));
        $recovered = 0;
        $revenueMinor = 0;
        foreach ($snapshots as $snapshot) {
            $cart = Cart::find(Arr::str($snapshot, 'cart_id'));
            if ($cart === null) continue;
            if (Arr::str(Arr::row($cart), 'state') !== 'converted') continue;
            $recovered++;
            $decoded = Arr::row(json_decode(Arr::str($snapshot, 'snapshot_json', '{}'), true));
            $revenueMinor += Arr::int($decoded, 'total_minor');
        }
        return ['stats' => [
            ['label' => 'Abandoned carts', 'value' => count($snapshots)],
            ['label' => 'Reminder emails sent', 'value' => $sent],
            ['label' => 'Recovered carts', 'value' => $recovered],
            ['label' => 'Recovered revenue (minor)', 'value' => $revenueMinor],
        ]];
    }

    /**
     * Admin snapshots table for a scope (newest first, max 50 rows).
     * @return array{table: array{columns: list<array{key: string, label: string}>, rows: list<array<string, string>>}}
     */
    public function snapshotsTable(CommerceScope $scope): array
    {
        $columns = [
            ['key' => 'cart_id', 'label' => 'Cart'],
            ['key' => 'customer_id', 'label' => 'Customer'],
            ['key' => 'total_minor', 'label' => 'Total (minor)'],
            ['key' => 'currency', 'label' => 'Currency'],
            ['key' => 'abandoned_at', 'label' => 'Abandoned at'],
        ];
        $rows = [];
        foreach ($this->snapshotRows($scope, 50) as $snapshot) {
            $totals = Arr::row(json_decode(Arr::str($snapshot, 'snapshot_json', '{}'), true));
            $rows[] = [
                'cart_id' => Arr::str($snapshot, 'cart_id'),
                'customer_id' => Arr::str($snapshot, 'customer_id'),
                'total_minor' => (string) Arr::int($totals, 'total_minor'),
                'currency' => Arr::str($totals, 'currency'),
                'abandoned_at' => Arr::str($snapshot, 'abandoned_at'),
            ];
        }
        return ['table' => ['columns' => $columns, 'rows' => $rows]];
    }

    /**
     * Admin deliveries table for a scope (newest first, max 50 rows).
     * @return array{table: array{columns: list<array{key: string, label: string}>, rows: list<array<string, string>>}}
     */
    public function deliveriesTable(CommerceScope $scope): array
    {
        $columns = [
            ['key' => 'cart_id', 'label' => 'Cart'],
            ['key' => 'signal', 'label' => 'Signal'],
            ['key' => 'step', 'label' => 'Step'],
            ['key' => 'sent_at', 'label' => 'Sent at'],
        ];
        $rows = [];
        foreach ($this->deliveryRows($scope, 50) as $delivery) {
            $rows[] = [
                'cart_id' => Arr::str($delivery, 'cart_id'),
                'signal' => Arr::str($delivery, 'signal'),
                'step' => (string) Arr::int($delivery, 'step'),
                'sent_at' => Arr::str($delivery, 'sent_at'),
            ];
        }
        return ['table' => ['columns' => $columns, 'rows' => $rows]];
    }

    /** @return list<array<string, mixed>> */
    private function snapshotRows(CommerceScope $scope, ?int $limit = null): array
    {
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $sql = 'SELECT * FROM ext_abandoned_cart_snapshots WHERE ' . $where . ' ORDER BY abandoned_at DESC';
        if ($limit !== null) $sql .= ' LIMIT ' . $limit;
        $rows = Database::select($sql, $params);
        return array_values(array_filter($rows, 'is_array'));
    }

    /** @return list<array<string, mixed>> */
    private function deliveryRows(CommerceScope $scope, ?int $limit = null): array
    {
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $sql = 'SELECT * FROM ext_abandoned_cart_deliveries WHERE ' . $where . ' ORDER BY sent_at DESC';
        if ($limit !== null) $sql .= ' LIMIT ' . $limit;
        $rows = Database::select($sql, $params);
        return array_values(array_filter($rows, 'is_array'));
    }
}
