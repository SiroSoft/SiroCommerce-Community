<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Provider-owned certification contracts. Secrets and response bodies never leave this class.
 *
 * @phpstan-type Contract array{
 *     provider: string,
 *     protocols: list<string>,
 *     health_url: string,
 *     webhook_url: string|null,
 *     credential_groups: list<list<string>>,
 *     webhook_secret: list<string>
 * }
 */
final class ProviderCertification
{
    /** @return array<string, Contract> */
    public static function matrix(): array
    {
        return [
            'payment' => ['provider' => self::env('PAYMENT_DEFAULT_PROVIDER', 'stripe'), 'protocols' => ['health', 'webhook'], 'health_url' => 'PAYMENT_LIVE_SMOKE_URL', 'webhook_url' => 'PAYMENT_LIVE_SMOKE_WEBHOOK_URL', 'credential_groups' => self::paymentCredentials(), 'webhook_secret' => ['PAYMENT_WEBHOOK_SECRET', 'PAYMENT_' . strtoupper((string) self::env('PAYMENT_DEFAULT_PROVIDER', 'stripe')) . '_WEBHOOK_SECRET', strtoupper((string) self::env('PAYMENT_DEFAULT_PROVIDER', 'stripe')) . '_WEBHOOK_SECRET']],
            'tax' => ['provider' => self::env('TAX_PROVIDER', 'native'), 'protocols' => ['health', 'webhook'], 'health_url' => 'TAX_LIVE_SMOKE_URL', 'webhook_url' => 'TAX_LIVE_SMOKE_WEBHOOK_URL', 'credential_groups' => [['TAX_API_KEY', 'TAX_PROVIDER_API_KEY']], 'webhook_secret' => ['TAX_WEBHOOK_SECRET', 'TAX_PROVIDER_WEBHOOK_SECRET']],
            // The runtime registry currently exposes DHL only. Do not certify an
            // arbitrary configured name against the DHL transport.
            'shipping' => ['provider' => 'dhl', 'protocols' => ['health', 'webhook'], 'health_url' => 'SHIPPING_LIVE_SMOKE_URL', 'webhook_url' => 'SHIPPING_LIVE_SMOKE_WEBHOOK_URL', 'credential_groups' => [['DHL_API_KEY'], ['DHL_API_SECRET']], 'webhook_secret' => ['DHL_WEBHOOK_SECRET']],
            'search' => ['provider' => self::env('SEARCH_PROVIDER', 'native'), 'protocols' => ['health'], 'health_url' => 'SEARCH_LIVE_SMOKE_URL', 'webhook_url' => null, 'credential_groups' => [['SEARCH_API_KEY']], 'webhook_secret' => []],
        ];
    }

    /** @return array<string, mixed> */
    public static function run(): array
    {
        $enabled = in_array(strtolower(self::env('PROVIDER_LIVE_SMOKE')), ['1', 'true'], true);
        $results = [];
        $blocked = false;
        foreach (self::matrix() as $domain => $contract) {
            $results[$domain] = ['provider' => $contract['provider'], 'health' => self::checkProtocol($domain, $contract, 'health', $enabled)];
            if (in_array($results[$domain]['health']['status'] ?? '', ['BLOCKED', 'BLOCKED_EXTERNAL'], true)) $blocked = true;
            if ($contract['webhook_url'] === null) {
                $results[$domain]['webhook'] = ['status' => 'NOT_APPLICABLE', 'reason' => 'Provider has no inbound webhook contract'];
            } else {
                $results[$domain]['webhook'] = self::checkProtocol($domain, $contract, 'webhook', $enabled);
                if (in_array($results[$domain]['webhook']['status'] ?? '', ['BLOCKED', 'BLOCKED_EXTERNAL'], true)) $blocked = true;
            }
        }
        $statuses = [];
        foreach ($results as $protocols) foreach ($protocols as $protocol) if (is_array($protocol) && isset($protocol['status'])) $statuses[] = $protocol['status'];
        $status = $blocked ? 'BLOCKED_EXTERNAL' : ($enabled && $statuses !== [] && count(array_filter($statuses, static fn (mixed $value): bool => $value === 'PASS')) === count(array_filter($statuses, static fn (mixed $value): bool => $value !== 'NOT_APPLICABLE')) ? 'PASS' : 'NOT_PROVEN');
        return ['status' => $status, 'matrix' => self::matrixForOutput(), 'results' => $results];
    }

    /** Deterministic local contract evidence; it is never live certification. */
    /** @return array{status: string, certification: string, reason: string, results: array<string, array{health: string, webhook?: string}>} */
    public static function localFixtures(): array
    {
        $results = [];
        foreach (self::matrix() as $domain => $contract) {
            $health = ['provider' => $contract['provider'], 'status' => 'healthy', 'authenticated' => true, 'protocol' => 'health'];
            $results[$domain] = ['health' => self::validHealthFixture($health, $contract['provider']) ? 'LOCAL_PASS' : 'FAIL'];
            if ($contract['webhook_url'] !== null) {
                $webhook = ['provider' => $contract['provider'], 'accepted' => true, 'authenticated' => true, 'protocol' => 'webhook', 'event_id' => 'provider-certification-fixture'];
                $results[$domain]['webhook'] = self::validWebhookFixture($webhook, $contract['provider'], 'provider-certification-fixture') ? 'LOCAL_PASS' : 'FAIL';
            }
        }
        return ['status' => 'LOCAL_PASS', 'certification' => 'BLOCKED_EXTERNAL', 'reason' => 'Local fixtures do not prove live provider certification', 'results' => $results];
    }

    /**
     * @param Contract $contract
     * @return array<string, mixed>
     */
    private static function checkProtocol(string $domain, array $contract, string $protocol, bool $enabled): array
    {
        if (!$enabled) return ['status' => 'BLOCKED_EXTERNAL', 'reason' => 'PROVIDER_LIVE_SMOKE is not enabled', 'owner' => 'Provider Operations', 'prerequisite' => 'PROVIDER_LIVE_SMOKE=1 and provider smoke credentials/endpoints', 'command' => 'PROVIDER_LIVE_SMOKE=1 php scripts/provider-live-smoke.php'];
        $missing = self::missingCredentials($contract);
        if ($missing !== []) return ['status' => 'BLOCKED_EXTERNAL', 'reason' => 'Missing configured provider credentials: ' . implode(', ', $missing), 'owner' => 'Provider Operations', 'prerequisite' => implode(', ', $missing), 'command' => 'php scripts/provider-preflight.php'];
        $urlVariable = $protocol === 'health' ? $contract['health_url'] : $contract['webhook_url'];
        if ($urlVariable === null) return ['status' => 'BLOCKED', 'reason' => 'Provider has no URL for the requested protocol'];
        $url = self::env($urlVariable);
        if ($url === '' || !self::httpsUrl($url)) return ['status' => 'BLOCKED_EXTERNAL', 'reason' => $urlVariable . ' must be an HTTPS URL', 'owner' => 'Provider Operations', 'prerequisite' => $urlVariable . '=https://...', 'command' => 'php scripts/provider-live-smoke.php'];
        $body = $protocol === 'webhook' ? json_encode(['event_id' => 'provider-certification-fixture', 'provider' => $contract['provider'], 'type' => 'health.check'], JSON_THROW_ON_ERROR) : null;
        $headers = ['Accept: application/json', 'User-Agent: sirocommerce-provider-certification/1'];
        $credential = self::firstCredential($contract['credential_groups']);
        if ($credential !== '') $headers[] = 'Authorization: Bearer ' . $credential;
        if ($protocol === 'webhook') {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'X-Webhook-Event: provider-certification-fixture';
            $headers[] = 'X-Webhook-Signature: ' . hash_hmac('sha256', (string) $body, self::firstValue($contract['webhook_secret']));
        }
        $context = stream_context_create(['http' => ['method' => $protocol === 'webhook' ? 'POST' : 'GET', 'content' => $body, 'timeout' => 10, 'ignore_errors' => true, 'header' => implode("\r\n", $headers)], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $started = hrtime(true);
        $http_response_header = [];
        $response = @file_get_contents($url, false, $context);
        $statusCode = self::statusCode($http_response_header);
        $result = ['endpoint' => (string) parse_url($url, PHP_URL_SCHEME) . '://' . (string) parse_url($url, PHP_URL_HOST), 'http_status' => $statusCode, 'elapsed_ms' => round((hrtime(true) - $started) / 1_000_000, 2), 'response_bytes' => is_string($response) ? strlen($response) : 0];
        if ($response === false && $statusCode === 0) return ['status' => 'BLOCKED', 'reason' => 'Target was unreachable; no HTTP response headers were received.'] + $result;
        $fixture = is_string($response) ? json_decode($response, true) : null;
        $valid = $protocol === 'health' ? self::validHealthFixture($fixture, $contract['provider']) : self::validWebhookFixture($fixture, $contract['provider'], 'provider-certification-fixture');
        return ['status' => $statusCode >= 200 && $statusCode < 300 && $valid ? 'PASS' : ($statusCode >= 400 ? 'FAIL' : 'NOT_PROVEN'), 'contract_provider' => $contract['provider'], 'fixture' => $protocol, 'reason' => $valid ? null : 'Authenticated ' . $protocol . ' fixture did not satisfy the provider contract'] + $result;
    }

    /** @param mixed $fixture */
    public static function validHealthFixture(mixed $fixture, string $provider): bool
    {
        return is_array($fixture) && strtolower(self::stringValue($fixture['provider'] ?? null)) === strtolower($provider) && strtolower(self::stringValue($fixture['status'] ?? null)) === 'healthy' && ($fixture['authenticated'] ?? false) === true && ($fixture['protocol'] ?? null) === 'health';
    }

    /** @param mixed $fixture */
    public static function validWebhookFixture(mixed $fixture, string $provider, ?string $eventId = null): bool
    {
        return is_array($fixture) && strtolower(self::stringValue($fixture['provider'] ?? null)) === strtolower($provider) && ($fixture['authenticated'] ?? false) === true && ($fixture['accepted'] ?? false) === true && ($fixture['protocol'] ?? null) === 'webhook' && is_string($fixture['event_id'] ?? null) && $fixture['event_id'] !== '' && ($eventId === null || $fixture['event_id'] === $eventId);
    }

    /**
     * @param Contract $contract
     * @return list<string>
     */
    private static function missingCredentials(array $contract): array
    {
        $missing = [];
        foreach ($contract['credential_groups'] as $group) if (self::firstValue($group) === '') $missing[] = implode('|', $group);
        if ($contract['webhook_url'] !== null && self::firstValue($contract['webhook_secret']) === '') $missing[] = 'webhook signing secret';
        return $missing;
    }

    /** @param list<list<string>> $groups */
    private static function firstCredential(array $groups): string { foreach ($groups as $group) { $value = self::firstValue($group); if ($value !== '') return $value; } return ''; }
    /** @param list<string> $names */
    private static function firstValue(array $names): string { foreach ($names as $name) { $value = self::env($name); if ($value !== '') return $value; } return ''; }
    private static function stringValue(mixed $value): string { return is_string($value) ? $value : ''; }
    private static function env(string $name, string $default = ''): string { $value = getenv($name); return is_string($value) && trim($value) !== '' ? trim($value) : $default; }
    private static function httpsUrl(string $url): bool { return filter_var($url, FILTER_VALIDATE_URL) !== false && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' && (string) parse_url($url, PHP_URL_HOST) !== ''; }
    /** @param array<int, string> $headers */
    /** @param array<int, string> $headers */
    private static function statusCode(array $headers): int { foreach ($headers as $header) if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match) === 1) return (int) $match[1]; return 0; }
    /** @return array<string, mixed> */
    private static function matrixForOutput(): array { $matrix = self::matrix(); foreach ($matrix as $domain => $contract) { foreach (['credential_groups', 'webhook_secret'] as $key) unset($matrix[$domain][$key]); } return $matrix; }
    /** @return list<list<string>> */
    private static function paymentCredentials(): array
    {
        return match (self::env('PAYMENT_DEFAULT_PROVIDER', 'stripe')) {
            'paypal' => [['PAYPAL_ACCESS_TOKEN', 'PAYMENT_PAYPAL_ACCESS_TOKEN']], 'adyen' => [['ADYEN_API_KEY', 'PAYMENT_ADYEN_API_KEY']], 'authorize_net' => [['AUTHORIZENET_TRANSACTION_KEY', 'PAYMENT_AUTHORIZENET_API_KEY']], 'square' => [['SQUARE_ACCESS_TOKEN', 'PAYMENT_SQUARE_ACCESS_TOKEN']], default => [['STRIPE_SECRET_KEY', 'PAYMENT_STRIPE_SECRET_KEY']],
        };
    }
}
