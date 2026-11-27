<?php

declare(strict_types=1);

namespace App\Modules\Consent\Application;

use App\Models\CustomerNotificationPreference;
use App\Modules\Consent\Domain\ConsentPolicy;
use App\Repositories\ConsentRecordRepository;
use App\Support\Arr;
use Siro\Core\Env;

/**
 * Pseudonymous consent ledger. Raw session ids and IPs never reach storage: the
 * service hashes the session id and HMACs the IP with a server-side salt.
 */
final class ConsentService
{
    public function __construct(private readonly ConsentRecordRepository $records)
    {
    }

    /** @return array{version: string, updated_at: string, sections: array<int, array{id: string, title: string, body: string}>} */
    public function policy(): array
    {
        return ConsentPolicy::all();
    }

    /**
     * @param array{necessary: bool, analytics: bool, marketing: bool} $choices
     * @return array<string, mixed>
     */
    public function record(array $choices, string $policyVersion, string $sessionId, string $ip, string $userAgent): array
    {
        if ($choices['necessary'] !== true) {
            throw new \InvalidArgumentException('Necessary storage must remain enabled');
        }
        if (trim($policyVersion) === '' || $policyVersion !== ConsentPolicy::version()) {
            throw new \InvalidArgumentException('Unsupported consent policy version');
        }

        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            // Anonymous fallback: still creates a record, but without a stable
            // idempotency key the client never gets this path (it sends a header).
            $sessionId = bin2hex(random_bytes(24));
        }
        if (strlen($sessionId) > 128) {
            throw new \InvalidArgumentException('Session id is too long');
        }

        $normalized = [
            'necessary' => true,
            'analytics' => $choices['analytics'] === true,
            'marketing' => $choices['marketing'] === true,
        ];
        $userAgent = substr(trim($userAgent), 0, 200);
        $ipHash = $this->hashIp($ip);
        $result = $this->records->store(hash('sha256', $sessionId), $policyVersion, $normalized, $ipHash, $userAgent === '' ? null : $userAgent);
        $record = $result['record'];

        return [
            'recorded' => true,
            'updated' => !$result['created'],
            'id' => $record['id'] ?? null,
            'policy_version' => $policyVersion,
            'choices' => $normalized,
            'created_at' => $record['created_at'] ?? null,
            'updated_at' => $record['updated_at'] ?? null,
        ];
    }

    /**
     * The single read API plugins use before sending marketing email.
     * For a known customer the source is CustomerNotificationPreference
     * (marketing_email) ONLY — consent_records is the cookie/GDPR ledger
     * and is never consulted for a customer. For an anonymous session
     * (no customer row exists) the session ledger is the only possible
     * source, so the session hash is resolved against it.
     */
    public function hasMarketingConsent(?string $customerId = null, ?string $sessionHash = null): bool
    {
        if ($customerId !== null && trim($customerId) !== '') {
            $row = CustomerNotificationPreference::query()->where('customer_id', trim($customerId))->first();
            return $row !== null && Arr::int(Arr::row($row), 'marketing_email') === 1;
        }
        if ($sessionHash !== null && trim($sessionHash) !== '') {
            $record = $this->records->findBySessionHash(hash('sha256', trim($sessionHash)));
            if ($record === null) return false;
            $choices = $record['choices_json'] ?? '';
            $decoded = json_decode(is_string($choices) ? $choices : '', true);
            return is_array($decoded) && ($decoded['marketing'] ?? false) === true;
        }
        return false;
    }

    /** @return array{records: int, analytics_opt_in: int, marketing_opt_in: int, policy_version: string} */
    public function summary(): array
    {
        $records = 0;
        $analytics = 0;
        $marketing = 0;
        foreach ($this->records->all() as $row) {
            $records++;
            $encodedChoices = $row['choices_json'] ?? null;
            if (!is_string($encodedChoices)) {
                continue;
            }
            $decoded = json_decode($encodedChoices, true);
            if (!is_array($decoded)) {
                continue;
            }
            if (($decoded['analytics'] ?? false) === true) {
                $analytics++;
            }
            if (($decoded['marketing'] ?? false) === true) {
                $marketing++;
            }
        }

        return [
            'records' => $records,
            'analytics_opt_in' => $analytics,
            'marketing_opt_in' => $marketing,
            'policy_version' => ConsentPolicy::version(),
        ];
    }

    private function hashIp(string $ip): string
    {
        $salt = (string) Env::get('CONSENT_HASH_SALT', (string) Env::get('APP_KEY', 'siro-consent'));
        if ($salt === '') {
            $salt = 'siro-consent';
        }
        return hash_hmac('sha256', trim($ip), $salt);
    }
}
