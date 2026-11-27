<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Consent\Application\ConsentService;
use App\Modules\Consent\Domain\ConsentPolicy;
use App\Modules\Customer\Application\CustomerCommerceService;
use App\Modules\Customer\Application\CustomerService;
use App\Modules\Platform\Infrastructure\CustomerEngagementService;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class ConsentController extends Controller
{
    public function __construct(
        private readonly ConsentService $consent,
        private readonly CustomerCommerceService $commerce,
        private readonly CustomerEngagementService $engagement,
        private readonly CustomerService $customers,
    ) {
    }

    public function policy(Request $request): Response
    {
        return $this->success($this->consent->policy(), 'Consent policy')
            ->header('Cache-Control', 'public, max-age=300');
    }

    public function store(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'necessary' => 'required|boolean',
            'analytics' => 'required|boolean',
            'marketing' => 'required|boolean',
            'policy_version' => 'required|string|max:32',
            'session_id' => 'nullable|string|max:128',
        ]);

        $necessary = $data['necessary'] ?? null;
        $analytics = $data['analytics'] ?? null;
        $marketing = $data['marketing'] ?? null;
        $policyVersion = $data['policy_version'] ?? null;
        if (!is_bool($necessary) || !is_bool($analytics) || !is_bool($marketing)) {
            return $this->error('Consent choices must be booleans', 422, [], 'INVALID_CONSENT');
        }
        if (!is_string($policyVersion) || trim($policyVersion) === '') {
            return $this->error('policy_version is required', 422, [], 'INVALID_CONSENT');
        }

        $headerSession = $request->header('X-Session-Id');
        $bodySession = $data['session_id'] ?? null;
        $sessionId = is_string($headerSession) && trim($headerSession) !== ''
            ? $headerSession
            : (is_string($bodySession) ? $bodySession : '');

        try {
            $result = $this->consent->record(
                ['necessary' => $necessary, 'analytics' => $analytics, 'marketing' => $marketing],
                $policyVersion,
                $sessionId,
                $request->ip(),
                (string) ($request->header('User-Agent', '') ?? ''),
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422, [], 'INVALID_CONSENT');
        }

        return $this->success($result, 'Consent recorded');
    }

    /**
     * Public marketing opt-in for plugins (Email Popup, Abandoned Cart).
     * Logged-in customer: writes CustomerNotificationPreference.marketing_email
     * (the single source ConsentService::hasMarketingConsent reads).
     * Anonymous session: records the choice in the consent ledger under the
     * session hash (no customer row exists yet to hold a preference).
     * A supplied email is validated but never persisted by core — the
     * subscribing plugin stores it in its own ext_* table.
     */
    public function marketing(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'marketing' => 'nullable|boolean',
            'session_id' => 'nullable|string|max:128',
            'email' => 'nullable|string',
        ]);
        $marketing = $data['marketing'] ?? true;
        if (!is_bool($marketing)) return $this->error('marketing must be a boolean', 422);
        $email = isset($data['email']) && is_string($data['email']) ? trim($data['email']) : '';
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) return $this->error('email is invalid', 422);

        $customer = $this->accountCustomer($request);
        if ($customer !== null) {
            try {
                $row = $this->engagement->updatePreferences(Arr::requiredStr($customer, 'id'), ['marketing_email' => $marketing], $this->idempotencyKey($request));
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                return $this->error($e->getMessage(), 422);
            }
            return $this->success(['customer_id' => Arr::str($row, 'customer_id'), 'marketing_email' => Arr::int($row, 'marketing_email') === 1], 'Marketing consent recorded');
        }

        $headerSession = $request->header('X-Session-Id');
        $bodySession = $data['session_id'] ?? null;
        $sessionId = is_string($headerSession) && trim($headerSession) !== ''
            ? $headerSession
            : (is_string($bodySession) ? $bodySession : '');
        if (trim($sessionId) === '') return $this->error('A customer login or session_id is required', 422);
        try {
            $this->consent->record(
                ['necessary' => true, 'analytics' => false, 'marketing' => $marketing],
                ConsentPolicy::version(),
                $sessionId,
                $request->ip(),
                (string) ($request->header('User-Agent', '') ?? ''),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, [], 'INVALID_CONSENT');
        }
        return $this->success(['session' => true, 'marketing_email' => $marketing], 'Marketing consent recorded');
    }

    /** @return array<string, mixed>|null Mirrors CustomerController::accountCustomer (email identity). */
    private function accountCustomer(Request $request): ?array
    {
        $user = $request->user();
        $email = is_array($user) && is_string($user['email'] ?? null) ? $user['email'] : '';
        if ($email === '') {
            // OptionalAuthMiddleware principals carry id/role only (no email);
            // resolve the email from the user row instead of failing the opt-in.
            $userId = is_array($user) && is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;
            if ($userId > 0) {
                $row = \App\Models\User::find($userId);
                $email = $row === null ? '' : Arr::str(Arr::row($row), 'email');
            }
        }
        if ($email === '') return null;
        $customer = $this->commerce->customerForEmail($email);
        if ($customer !== null) return $customer;
        try {
            $this->customers->register($email, is_array($user) && is_string($user['name'] ?? null) ? $user['name'] : '');
            return $this->commerce->customerForEmail($email);
        } catch (\Throwable) {
            return null;
        }
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key', '');
        return is_string($key) ? $key : '';
    }

    public function summary(Request $request): Response
    {
        return $this->success($this->consent->summary(), 'Consent summary')
            ->header('Cache-Control', 'private, no-store');
    }
}
