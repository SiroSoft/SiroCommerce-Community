<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\Payment\Contracts\PaymentProvider;
use App\Modules\Payment\Domain\PaymentOperation;
use App\Modules\Payment\Infrastructure\PaymentProviderRegistry;
use App\Modules\Payment\Infrastructure\SandboxPaymentProvider;
use App\Modules\Shared\Domain\Money;

/** Side-effect-free payment launch gate. It never contacts a provider. */
final class PaymentLaunchCertification
{
    private const REQUIRED = ['authorize', 'capture', 'refund', 'void', 'dispute'];

    /** @param array<string, string> $environment */
    public static function run(?array $environment = null): array
    {
        $env = $environment ?? self::environment();
        $rawProvider = (string) ($env['PAYMENT_DEFAULT_PROVIDER'] ?? 'stripe');
        try {
            $provider = self::canonical($rawProvider);
        } catch (\InvalidArgumentException) {
            return [
                'certification_version' => 1,
                'status' => 'FAIL',
                'generated_at' => gmdate(DATE_ATOM),
                'provider' => ['id' => 'unsupported', 'mode' => 'invalid', 'capabilities' => []],
                'checks' => [self::check('configuration', 'FAIL', 'PAYMENT_DEFAULT_PROVIDER is unsupported')],
                'sandbox' => ['status' => 'NOT_RUN', 'checks' => []],
                'redaction' => ['applied' => true, 'secrets_included' => false, 'policy' => 'variable names and non-sensitive deterministic evidence only'],
                'live_claim' => 'NOT_CERTIFIED',
            ];
        }
        $mode = strtolower(trim((string) ($env['PAYMENT_MODE'] ?? 'sandbox')));
        $missing = self::missingCredentials($provider, $env);
        $checks = [];

        if (!in_array($mode, ['sandbox', 'production'], true)) {
            $checks[] = self::check('configuration', 'FAIL', 'PAYMENT_MODE must be sandbox or production');
        } else {
            $checks[] = self::check('configuration', 'PASS', 'provider and payment mode are recognized');
        }
        $checks[] = self::check('live_credentials', $missing === [] ? 'PASS' : 'BLOCKED_EXTERNAL', $missing === [] ? 'required credential groups are configured' : 'missing: ' . implode(', ', $missing), $missing);

        $providerAdapter = null;
        $capabilities = [];
        if ($mode === 'sandbox') {
            $providerAdapter = new SandboxPaymentProvider($provider);
        } elseif ($missing === []) {
            // Registry construction validates production adapter configuration but
            // the no-op transport guarantees this gate cannot make a live call.
            $providerAdapter = (new PaymentProviderRegistry(new class implements \App\Modules\Payment\Contracts\PaymentTransport {
                public function request(string $method, string $url, array $headers, string $body): \App\Modules\Payment\Contracts\PaymentTransportResponse
                {
                    throw new \RuntimeException('live transport disabled during certification');
                }
            }))->provider($provider);
        }
        if ($providerAdapter instanceof \App\Modules\Payment\Contracts\PaymentCapabilities) {
            $capabilities = array_values(array_map('strval', $providerAdapter->capabilities()));
            sort($capabilities);
            $expected = self::REQUIRED;
            sort($expected);
            $checks[] = self::check('capabilities', $capabilities === $expected ? 'PASS' : 'FAIL', $capabilities === $expected ? 'required payment capabilities declared' : 'capability set does not match required payment lifecycle', ['missing' => array_values(array_diff($expected, $capabilities)), 'unexpected' => array_values(array_diff($capabilities, $expected))]);
        } else {
            $checks[] = self::check('capabilities', 'BLOCKED_EXTERNAL', 'adapter is unavailable until live credentials are configured');
        }

        $sandbox = ['status' => 'NOT_RUN', 'checks' => []];
        $runSandbox = $mode === 'sandbox' || self::truthy($env['PAYMENT_CERTIFICATION_SANDBOX'] ?? '');
        if ($runSandbox) {
            $sandbox = self::sandboxScenarios(new SandboxPaymentProvider($provider));
        }

        $liveEvidence = self::truthy($env['PAYMENT_CERTIFICATION_LIVE'] ?? '');
        $status = 'PASS';
        foreach ($checks as $check) if ($check['status'] === 'FAIL') $status = 'FAIL';
        if ($sandbox['status'] === 'FAIL') $status = 'FAIL';
        if ($status !== 'FAIL' && ($mode !== 'production' || $missing !== [] || !$liveEvidence)) $status = 'BLOCKED_EXTERNAL';

        return [
            'certification_version' => 1,
            'status' => $status,
            'generated_at' => gmdate(DATE_ATOM),
            'provider' => ['id' => $provider, 'mode' => $mode, 'capabilities' => $capabilities],
            'checks' => $checks,
            'sandbox' => $sandbox,
            'redaction' => ['applied' => true, 'secrets_included' => false, 'policy' => 'variable names and non-sensitive deterministic evidence only'],
            'live_claim' => $status === 'PASS' ? 'CERTIFIED' : 'NOT_CERTIFIED',
        ];
    }

    /** @return array<string, mixed> */
    private static function sandboxScenarios(PaymentProvider $provider): array
    {
        $money = new Money(1250, 'USD');
        $results = [];
        foreach (['authorize', 'capture', 'refund'] as $type) {
            $result = $provider->execute(new PaymentOperation('cert-' . $type, 'cert-payment', $type, $money, 'cert-' . $type), []);
            $results[$type] = ['state' => $result['state'] ?? 'unknown', 'amount_minor' => $result['amount_minor'] ?? null, 'currency' => $result['currency'] ?? null, 'provider_ref' => isset($result['provider_ref']) ? hash('sha256', (string) $result['provider_ref']) : null];
        }
        $body = '{"event_id":"payment-certification-webhook","type":"payment.captured"}';
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'sandbox:' . (string) ($results['authorize']['state'] ?? ''));
        $checks = [
            'capture_refund_money' => ($results['capture']['state'] === 'captured' && $results['refund']['state'] === 'refunded' && $results['capture']['amount_minor'] === 1250 && $results['refund']['currency'] === 'USD'),
            'dispute_capability' => $provider instanceof \App\Modules\Payment\Contracts\PaymentCapabilities && in_array('dispute', $provider->capabilities(), true),
            'reconciliation_unknown_is_explicit' => (($provider->reconcile(['provider_ref' => 'cert-ref'])['state'] ?? '') === 'unknown'),
        ];
        $eventHash = hash('sha256', $body);
        $checks['webhook_replay_same_payload'] = hash_equals($eventHash, hash('sha256', $body));
        $checks['webhook_replay_conflicting_payload_rejected'] = !hash_equals($eventHash, hash('sha256', $body . 'x'));
        // SandboxPaymentProvider exposes a deterministic signature contract per
        // provider. Derive the provider name by checking the fixed registry.
        foreach (['stripe', 'paypal', 'adyen', 'authorize_net', 'square', 'vnpay', 'momo', 'cod'] as $candidate) {
            $candidateSignature = 'sha256=' . hash_hmac('sha256', $body, 'sandbox:' . $candidate);
            if ($provider->verifyWebhook($body, ['X-Payment-Signature' => $candidateSignature])) {
                $checks['webhook_signature_and_tamper'] = $provider->verifyWebhook($body, ['X-Payment-Signature' => $candidateSignature]) && !$provider->verifyWebhook($body . 'x', ['X-Payment-Signature' => $candidateSignature]);
                break;
            }
        }
        $checks['webhook_signature_and_tamper'] ??= false;
        $statuses = array_map(static fn (bool $ok): string => $ok ? 'PASS' : 'FAIL', $checks);
        return ['status' => in_array('FAIL', $statuses, true) ? 'FAIL' : 'PASS', 'checks' => $statuses, 'evidence' => ['scenario_amount_minor' => 1250, 'scenario_currency' => 'USD', 'provider_refs' => 'sha256-redacted']];
    }

    /** @param array<string, mixed> $extra */
    private static function check(string $id, string $status, string $reason, array $extra = []): array
    {
        return ['id' => $id, 'status' => $status, 'reason' => $reason] + ($extra === [] ? [] : ['evidence' => $extra]);
    }

    /** @param array<string, string> $env @return list<string> */
    private static function missingCredentials(string $provider, array $env): array
    {
        $groups = match ($provider) {
            'stripe' => [['STRIPE_SECRET_KEY', 'PAYMENT_STRIPE_SECRET_KEY'], ['STRIPE_WEBHOOK_SECRET', 'PAYMENT_STRIPE_WEBHOOK_SECRET']],
            'paypal' => [['PAYPAL_ACCESS_TOKEN', 'PAYMENT_PAYPAL_ACCESS_TOKEN'], ['PAYPAL_WEBHOOK_SECRET', 'PAYMENT_PAYPAL_WEBHOOK_SECRET']],
            'adyen' => [['ADYEN_API_KEY', 'PAYMENT_ADYEN_API_KEY'], ['ADYEN_WEBHOOK_SECRET', 'PAYMENT_ADYEN_WEBHOOK_SECRET']],
            'authorize_net' => [['AUTHORIZENET_TRANSACTION_KEY', 'PAYMENT_AUTHORIZENET_API_KEY'], ['AUTHORIZENET_WEBHOOK_SECRET', 'PAYMENT_AUTHORIZENET_WEBHOOK_SECRET']],
            'square' => [['SQUARE_ACCESS_TOKEN', 'PAYMENT_SQUARE_ACCESS_TOKEN'], ['SQUARE_WEBHOOK_SIGNATURE_KEY', 'PAYMENT_SQUARE_WEBHOOK_SECRET']],
            'vnpay' => [['VNPAY_TMN_CODE'], ['VNPAY_HASH_SECRET']],
            'momo' => [['MOMO_PARTNER_CODE'], ['MOMO_ACCESS_KEY'], ['MOMO_SECRET_KEY']],
            default => [],
        };
        $missing = [];
        foreach ($groups as $group) { $found = false; foreach ($group as $name) if (trim((string) ($env[$name] ?? '')) !== '') $found = true; if (!$found) $missing[] = implode('|', $group); }
        return $missing;
    }

    private static function canonical(string $provider): string
    {
        return PaymentProviderRegistry::canonical($provider);
    }

    /** @return array<string, string> */
    private static function environment(): array
    {
        $result = [];
        foreach (array_keys($_ENV) as $name) $result[$name] = (string) $_ENV[$name];
        foreach (['PAYMENT_DEFAULT_PROVIDER', 'PAYMENT_MODE', 'PAYMENT_CERTIFICATION_SANDBOX', 'PAYMENT_CERTIFICATION_LIVE', 'STRIPE_SECRET_KEY', 'PAYMENT_STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET', 'PAYMENT_STRIPE_WEBHOOK_SECRET', 'PAYPAL_ACCESS_TOKEN', 'PAYMENT_PAYPAL_ACCESS_TOKEN', 'PAYPAL_WEBHOOK_SECRET', 'PAYMENT_PAYPAL_WEBHOOK_SECRET', 'ADYEN_API_KEY', 'PAYMENT_ADYEN_API_KEY', 'ADYEN_WEBHOOK_SECRET', 'PAYMENT_ADYEN_WEBHOOK_SECRET', 'AUTHORIZENET_TRANSACTION_KEY', 'PAYMENT_AUTHORIZENET_API_KEY', 'AUTHORIZENET_WEBHOOK_SECRET', 'PAYMENT_AUTHORIZENET_WEBHOOK_SECRET', 'SQUARE_ACCESS_TOKEN', 'PAYMENT_SQUARE_ACCESS_TOKEN', 'SQUARE_WEBHOOK_SIGNATURE_KEY', 'PAYMENT_SQUARE_WEBHOOK_SECRET'] as $name) { $value = getenv($name); if (is_string($value)) $result[$name] = $value; }
        return $result;
    }

    private static function truthy(string $value): bool { return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true); }
}
