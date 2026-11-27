<?php

declare(strict_types=1);

namespace App\Extensions\Siro\EmailCapturePopup;

use App\Modules\Consent\Application\ConsentService;
use App\Modules\Coupon\Application\CouponService;
use App\Modules\Customer\Contracts\CustomerReader;
use App\Modules\Extension\Application\ExtensionScope;
use App\Modules\Mail\Application\MailTemplateRegistry;
use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\Ids;
use Siro\Core\Database;

/**
 * Email capture subscribers, scoped by store/channel (EXTENSION_SCOPING.md).
 *
 * Every read/write filters both scope columns through ExtensionScope — never
 * a hand-written fragment. A subscriber is one row per (store, channel,
 * email); re-subscribing returns the existing row and never re-sends the
 * welcome mail.
 *
 * Welcome mail is sent ONLY when ConsentService::hasMarketingConsent() is
 * true for the subscriber (customer preference when a customer row exists
 * for the email, session ledger otherwise). The consent opt-in itself is
 * recorded by core POST /api/consent/marketing before this plugin is called;
 * this service never writes consent, it only reads it.
 */
final class EmailCaptureService
{
    public const EVENT_SUBSCRIBED = 'email_capture.subscribed';
    public const MAX_EMAIL_LENGTH = 254;
    public const MAX_SOURCE_LENGTH = 32;
    public const COUPON_PERCENT = 10;

    private const COLUMNS = 'id, store_id, channel_id, email, customer_id, source, coupon_code, consented_at, created_at, updated_at';

    public function __construct(
        private readonly ConsentService $consent,
        private readonly CouponService $coupons,
        private readonly CustomerReader $customers,
    ) {
    }

    /** @return array{active: bool, delay_ms: int, coupon_enabled: bool, coupon_percent: int} */
    public function publicConfig(CommerceScope $scope): array
    {
        return [
            'active' => true,
            'delay_ms' => Extension::CONFIG['default_delay_ms'],
            'coupon_enabled' => (bool) Extension::CONFIG['default_coupon_enabled'],
            'coupon_percent' => self::COUPON_PERCENT,
        ];
    }

    /**
     * Subscribe an email. Idempotent per (store, channel, email): a replay
     * returns the existing row with already_subscribed=true and sends no
     * second mail. The welcome coupon + mail are issued only when marketing
     * consent is verified; without consent the row is stored with
     * consented_at=null and no mail is sent.
     *
     * @return array<string, mixed>
     */
    public function subscribe(CommerceScope $scope, string $email, string $source, ?string $sessionId): array
    {
        $email = strtolower(trim($email));
        if ($email === '' || strlen($email) > self::MAX_EMAIL_LENGTH || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('email is invalid');
        }
        $source = trim($source) === '' ? 'popup' : substr(trim($source), 0, self::MAX_SOURCE_LENGTH);
        if (preg_match('/^[A-Za-z0-9_-]+$/', $source) !== 1) {
            throw new \InvalidArgumentException('source is invalid');
        }

        $existing = $this->findByEmail($scope, $email);
        if ($existing !== null) {
            /** @var array<string, mixed> $result */
            $result = $this->present($existing);
            $result['already_subscribed'] = true;
            $result['mail_sent'] = false;
            return $result;
        }

        $customer = $this->customers->findByEmail($email);
        $customerId = $customer !== null ? Arr::str($customer, 'id') : '';
        $consented = $this->hasConsent($customerId, $sessionId);
        $now = date('Y-m-d H:i:s');

        $couponCode = null;
        if ($consented) {
            $couponCode = $this->createWelcomeCoupon();
        }

        $id = Ids::new();
        $row = ExtensionScope::merge([
            'id' => $id,
            'email' => $email,
            'customer_id' => $customerId !== '' ? $customerId : null,
            'source' => $source,
            'coupon_code' => $couponCode,
            'consented_at' => $consented ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $scope);
        try {
            Database::table('ext_email_capture_subscribers')->insert($row);
        } catch (\Throwable $exception) {
            // Concurrent double-submit: the unique (store, channel, email) key
            // lets the first writer win; return its row like a replay.
            $winner = $this->findByEmail($scope, $email);
            if ($winner !== null) {
                /** @var array<string, mixed> $result */
                $result = $this->present($winner);
                $result['already_subscribed'] = true;
                $result['mail_sent'] = false;
                return $result;
            }
            throw new \RuntimeException('Subscriber could not be saved', 0, $exception);
        }

        $mailSent = false;
        if ($consented) {
            $mailSent = MailTemplateRegistry::send(self::EVENT_SUBSCRIBED, $email, [
                'customer_name' => $this->nameFromEmail($email),
                'coupon_code' => $couponCode ?? '',
            ]);
        }

        $saved = $this->findByEmail($scope, $email);
        if ($saved === null) {
            throw new \RuntimeException('Subscriber could not be saved');
        }
        /** @var array<string, mixed> $result */
        $result = $this->present($saved);
        $result['already_subscribed'] = false;
        $result['mail_sent'] = $mailSent;
        return $result;
    }

    /**
     * @return array{total: int, consented: int, with_coupon: int, today: int}
     */
    public function stats(CommerceScope $scope): array
    {
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $total = $this->countWhere($where, $params);
        $consented = $this->countWhere($where . ' AND consented_at IS NOT NULL', $params);
        $withCoupon = $this->countWhere($where . ' AND coupon_code IS NOT NULL', $params);
        $today = $this->countWhere($where . ' AND created_at >= ?', array_merge($params, [date('Y-m-d 00:00:00')]));
        return ['total' => $total, 'consented' => $consented, 'with_coupon' => $withCoupon, 'today' => $today];
    }

    /**
     * Scoped, paginated subscriber list (newest first). The WHERE always
     * carries both scope columns; pagination never widens the result set.
     *
     * @return array{total: int, page: int, per_page: int, rows: list<array<string, mixed>>}
     */
    public function list(CommerceScope $scope, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $total = $this->countWhere($where, $params);
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ext_email_capture_subscribers WHERE ' . $where
            . ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?'
        );
        if (!$stmt instanceof \PDOStatement) {
            throw new \RuntimeException('Subscribers could not be listed');
        }
        $stmt->execute(array_merge($params, [$perPage, ($page - 1) * $perPage]));
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $presented = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            ExtensionScope::assertScoped($row, $scope);
            $presented[] = $this->present($row);
        }
        return ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'rows' => $presented];
    }

    /** Scoped delete: the row id alone is never enough — scope must match. */
    public function delete(CommerceScope $scope, string $id): bool
    {
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $stmt = Database::connection()->prepare(
            'DELETE FROM ext_email_capture_subscribers WHERE ' . $where . ' AND id = ?'
        );
        if (!$stmt instanceof \PDOStatement) {
            throw new \RuntimeException('Subscriber could not be deleted');
        }
        $stmt->execute(array_merge($params, [$id]));
        return $stmt->rowCount() > 0;
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(CommerceScope $scope, string $email): ?array
    {
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ext_email_capture_subscribers WHERE ' . $where . ' AND email = ? LIMIT 1'
        );
        if (!$stmt instanceof \PDOStatement) {
            return null;
        }
        $stmt->execute(array_merge($params, [strtolower(trim($email))]));
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        /** @var array<string, mixed> $row */
        ExtensionScope::assertScoped($row, $scope);
        return $row;
    }

    private function hasConsent(string $customerId, ?string $sessionId): bool
    {
        if ($customerId !== '') {
            return $this->consent->hasMarketingConsent($customerId);
        }
        $session = is_string($sessionId) ? trim($sessionId) : '';
        if ($session === '') {
            return false;
        }
        return $this->consent->hasMarketingConsent(null, $session);
    }

    private function createWelcomeCoupon(): ?string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $code = 'WELCOME-' . strtoupper(bin2hex(random_bytes(4)));
            try {
                $this->coupons->create([
                    'code' => $code,
                    'rules' => json_encode(['type' => 'percent', 'value' => self::COUPON_PERCENT], JSON_THROW_ON_ERROR),
                    'state' => 'active',
                ]);
                return $code;
            } catch (\Throwable) {
                continue;
            }
        }
        return null;
    }

    /** @param list<string> $params */
    private function countWhere(string $where, array $params): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM ext_email_capture_subscribers WHERE ' . $where
        );
        if (!$stmt instanceof \PDOStatement) {
            throw new \RuntimeException('Subscribers could not be counted');
        }
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => Arr::str($row, 'id'),
            'store_id' => Arr::str($row, 'store_id'),
            'channel_id' => Arr::str($row, 'channel_id'),
            'email' => Arr::str($row, 'email'),
            'customer_id' => Arr::str($row, 'customer_id'),
            'source' => Arr::str($row, 'source'),
            'coupon_code' => Arr::str($row, 'coupon_code'),
            'consented_at' => Arr::str($row, 'consented_at'),
            'created_at' => Arr::str($row, 'created_at'),
            'updated_at' => Arr::str($row, 'updated_at'),
        ];
    }

    private function nameFromEmail(string $email): string
    {
        $parts = explode('@', $email);
        $local = $parts[0];
        $name = trim(str_replace(['.', '_', '-'], ' ', $local));
        return $name !== '' ? $name : 'there';
    }
}
