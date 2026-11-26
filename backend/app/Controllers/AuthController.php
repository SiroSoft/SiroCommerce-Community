<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Auth\Application\AuthService;
use App\Modules\Auth\Application\PermissionResolver;
use App\Support\Arr;
use App\Support\LocaleResolver;
use App\Support\Mailer;
use App\Support\Turnstile;
use App\Support\PublicErrorMessage;
use Siro\Core\Controller;
use Siro\Core\Lang;
use Siro\Core\Request;
use Siro\Core\Response;

final class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function register(Request $request): Response
    {
        Lang::setLocale(LocaleResolver::resolve($request));
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => 'required|string',
            'email' => 'required|string',
            'password' => 'required|string',
            'cf-turnstile-response' => 'nullable|string',
        ]);
        if (!Turnstile::verify(Arr::str($data, 'cf-turnstile-response'))) {
            $message = Turnstile::failureMessage(Arr::str($data, 'cf-turnstile-response'));
            return $this->error($message, 422, ['cf-turnstile-response' => [$message]]);
        }
        try {
            $user = $this->auth->register($data);
        } catch (\RuntimeException $e) {
            $message = PublicErrorMessage::from($e->getMessage(), 'Registration could not be completed');
            return $this->error($message, 422, ['email' => [$message]]);
        }
        // A registered shopper is a customer from the first second: without a
        // customer profile every account endpoint answers 404 for a brand-new
        // account. Provisioning is idempotent and never fails registration.
        $this->ensureCustomerProfile(Arr::str($data, 'email'), Arr::str($data, 'name'));
        /** @var array<string, mixed> $user */
        return $this->created($this->auth->publicUser($user), 'Registered');
    }

    /** @return array<string, mixed>|null */
    private function ensureCustomerProfile(string $email, string $name): ?array
    {
        if ($email === '') return null;
        try {
            $customers = \Siro\Core\Container::getInstance()->make(\App\Modules\Customer\Application\CustomerService::class);
            if (!$customers instanceof \App\Modules\Customer\Application\CustomerService) return null;
            return $customers->register($email, $name);
        } catch (\Throwable) {
            // Best effort: account endpoints provision lazily where needed.
            return null;
        }
    }

    public function login(Request $request): Response
    {
        Lang::setLocale(LocaleResolver::resolve($request));
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'email' => 'required|string',
            'password' => 'required|string',
            'cf-turnstile-response' => 'nullable|string',
        ]);
        if (!Turnstile::verify(Arr::str($data, 'cf-turnstile-response'))) {
            $message = Turnstile::failureMessage(Arr::str($data, 'cf-turnstile-response'));
            return $this->error($message, 422, ['cf-turnstile-response' => [$message]]);
        }
        try {
            $result = $this->auth->login(Arr::requiredStr($data, 'email'), Arr::requiredStr($data, 'password'));
        } catch (\RuntimeException $e) {
            return $this->error('Invalid credentials', 401);
        }
        return $this->success($result->toArray(), 'Logged in');
    }

    public function refresh(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['refresh' => 'nullable|string', 'refresh_token' => 'nullable|string']);
        $refreshToken = Arr::str($data, 'refresh') !== '' ? Arr::str($data, 'refresh') : Arr::str($data, 'refresh_token');
        if ($refreshToken === '') return $this->error('A refresh token is required', 422);
        try {
            $result = $this->auth->refresh($refreshToken);
        } catch (\RuntimeException $e) {
            return $this->error('Invalid refresh token', 401);
        }
        return $this->success($result->toArray(), 'Refreshed');
    }

    public function me(Request $request): Response
    {
        $user = $request->user();
        if (!is_array($user)) {
            return $this->error('Unauthorized', 401);
        }
        /** @var array<string, mixed> $user */
        $role = Arr::str($user, 'role');
        return $this->success([
            'id' => Arr::int($user, 'id'),
            'name' => Arr::str($user, 'name'),
            'email' => Arr::str($user, 'email'),
            'role' => $role,
            'permissions' => PermissionResolver::forRole($role),
            'email_verified_at' => is_string($user['email_verified_at'] ?? null) ? $user['email_verified_at'] : null,
        ], 'OK');
    }

    public function forgotPassword(Request $request): Response
    {
        Lang::setLocale(LocaleResolver::resolve($request));
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'email' => 'required|string',
            'cf-turnstile-response' => 'nullable|string',
        ]);
        if (!Turnstile::verify(Arr::str($data, 'cf-turnstile-response'))) {
            $message = Turnstile::failureMessage(Arr::str($data, 'cf-turnstile-response'));
            return $this->error($message, 422, ['cf-turnstile-response' => [$message]]);
        }
        $token = $this->auth->forgotPassword(Arr::requiredStr($data, 'email'));
        if ($token !== null && Mailer::isConfigured()) {
            $link = self::storefrontUrl() . '/reset-password?token=' . urlencode($token);
            Mailer::send(Arr::requiredStr($data, 'email'), 'Reset your SiroCommerce password', "Reset your password with this link (valid 1 hour):\n{$link}\n\nIf you did not ask for this, ignore this email.");
        }
        // Local/dev handoff: expose the raw token so console E2E can complete
        // the reset journey without a mailer. Production omits it.
        $env = strtolower((string) (\Siro\Core\Env::get('APP_ENV', 'production')));
        $debug = $token !== null && in_array($env, ['local', 'testing', 'development'], true);
        return $this->success($debug ? ['sent' => true, 'reset_token' => $token] : null, 'If the account exists, a reset link has been sent');
    }

    public function resetPassword(Request $request): Response
    {
        Lang::setLocale(LocaleResolver::resolve($request));
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'token' => 'required|string',
            'password' => 'required|string',
            'password_confirmation' => 'nullable|string',
        ]);
        $confirmation = Arr::str($data, 'password_confirmation');
        if ($confirmation !== '' && !hash_equals(Arr::requiredStr($data, 'password'), $confirmation)) {
            return $this->error('Password confirmation does not match', 422, ['password_confirmation' => ['Passwords do not match']]);
        }
        try {
            $this->auth->resetPassword(Arr::requiredStr($data, 'token'), Arr::requiredStr($data, 'password'));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->error(PublicErrorMessage::from($e->getMessage(), 'Password could not be reset'), 422);
        }
        return $this->success(null, 'Password has been reset');
    }

    public function verifyEmail(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['token' => 'required|string']);
        try {
            $user = $this->auth->verifyEmail(Arr::requiredStr($data, 'token'));
        } catch (\RuntimeException $e) {
            return $this->error('Verification link is invalid or expired', 422);
        }
        return $this->success($this->auth->publicUser($user), 'Email verified');
    }

    public function resendVerificationEmail(Request $request): Response
    {
        $user = $request->user();
        if (!is_array($user)) return $this->error('Unauthorized', 401);
        try {
            $token = $this->auth->resendVerificationEmail(Arr::int($user, 'id'));
        } catch (\RuntimeException $e) {
            return $this->error('Verification email could not be sent', 422);
        }
        if (Mailer::isConfigured()) {
            $link = self::storefrontUrl() . '/verify-email?token=' . urlencode($token);
            Mailer::send(Arr::str($user, 'email'), 'Verify your SiroCommerce email', "Confirm your email with this link (valid 24 hours):\n{$link}\n\nIf you did not create this account, ignore this email.");
        }
        $env = strtolower((string) (\Siro\Core\Env::get('APP_ENV', 'production')));
        $debug = in_array($env, ['local', 'testing', 'development'], true);
        return $this->success($debug ? ['sent' => true, 'verification_token' => $token] : null, 'Verification email sent');
    }

    public function updateProfile(Request $request): Response
    {
        $user = $request->user();
        if (!is_array($user)) return $this->error('Unauthorized', 401);
        /** @var array<string, mixed> $data */
        $data = $request->validate(['name' => 'required|string', 'birthdate' => 'nullable|string']);
        try {
            $birthdate = array_key_exists('birthdate', $data) && is_string($data['birthdate']) ? $data['birthdate'] : null;
            $updated = $this->auth->updateProfile(Arr::int($user, 'id'), Arr::requiredStr($data, 'name'), $birthdate);
            return $this->success($this->auth->publicUser($updated), 'Profile updated');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->error($e->getMessage(), 422, ['name' => [$e->getMessage()]]);
        }
    }

    private static function storefrontUrl(): string
    {
        $url = trim((string) (\Siro\Core\Env::get('STOREFRONT_URL', '') !== '' ? \Siro\Core\Env::get('STOREFRONT_URL', '') : \Siro\Core\Env::get('APP_URL', 'https://ecommerce.sirophp.com')));
        return rtrim($url, '/') ?: 'https://ecommerce.sirophp.com';
    }

    public function logout(Request $request): Response
    {
        $user = $request->user();
        /** @var array<string, mixed>|null $user */
        if (is_array($user)) {
            $this->auth->logout(Arr::int($user, 'id'));
        }
        return $this->success(null, 'Logged out');
    }
}
