<?php

declare(strict_types=1);

namespace App\Support;

use Siro\Core\Env;
use Siro\Core\Mail;
use Siro\Core\Mail\MailProvider;

/**
 * Transactional mail facade over Siro\Core\Mail.
 *
 * Delivery goes through the core transport (SmtpMailProvider by default,
 * NullMailProvider when MAIL_PROVIDER=null, or anything injected via
 * useProvider()/Mail::setProvider()) using never-throw Mail::trySend().
 *
 * Env names: core reads MAIL_HOST/MAIL_PORT/MAIL_USERNAME/MAIL_PASSWORD/
 * MAIL_ENCRYPTION/MAIL_FROM_ADDRESS/MAIL_FROM_NAME. The legacy MAIL_SMTP_*
 * names this app used before the core contract existed keep working:
 * bridgeEnv() copies them over (only for keys the operator did not set
 * explicitly), so the live .env needs no change.
 *
 * send() never throws: delivery failures are logged and reported as false,
 * and callers must keep user-facing responses identical either way so account
 * existence cannot be probed.
 *
 * Core Mail carries a single body: when both text and html are given, html
 * wins (no caller passes html today — all transactional mail is text-only).
 */
final class Mailer
{
    private static ?MailProvider $provider = null;

    /** @var list<string> core env keys populated by bridgeEnv() */
    private static array $bridged = [];

    /** Override the transport (tests, API-based drivers). */
    public static function useProvider(?MailProvider $provider): void
    {
        self::$provider = $provider;
    }

    /** Clear the override and any bridged env keys (test isolation). */
    public static function reset(): void
    {
        self::$provider = null;
        foreach (self::$bridged as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
        self::$bridged = [];
    }

    public static function isConfigured(): bool
    {
        if (self::$provider !== null) return true;
        if (self::silenced()) return false;
        return self::legacyConfigured() || self::coreConfigured();
    }

    public static function send(string $to, string $subject, string $text, ?string $html = null): bool
    {
        if (filter_var(trim($to), FILTER_VALIDATE_EMAIL) === false) return false;
        if (!self::isConfigured()) return false;
        try {
            self::bridgeEnv();
            if (self::$provider !== null) {
                return self::$provider->send(self::payload(trim($to), $subject, $text, $html));
            }
            $mail = Mail::to(trim($to))->subject($subject)->text($text);
            if ($html !== null) $mail = $mail->html($html);
            return $mail->trySend();
        } catch (\Throwable $e) {
            error_log('[mailer] send failed: ' . $e->getMessage());
            return false;
        }
    }

    /** @return array<string, mixed> */
    private static function payload(string $to, string $subject, string $text, ?string $html): array
    {
        return [
            'to' => $to,
            'subject' => $subject,
            'body' => $html ?? $text,
            'content_type' => $html !== null ? 'text/html' : 'text/plain',
            'charset' => 'UTF-8',
            'cc' => [],
            'bcc' => [],
            'reply_to' => '',
            'attachments' => [],
        ];
    }

    private static function silenced(): bool
    {
        $name = strtolower(trim((string) (Env::get('MAIL_PROVIDER', '') ?? '')));
        return in_array($name, ['null', 'noop', 'log'], true);
    }

    private static function legacyConfigured(): bool
    {
        return trim((string) Env::get('MAIL_SMTP_HOST', '')) !== ''
            && filter_var(trim((string) Env::get('MAIL_FROM', '')), FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function coreConfigured(): bool
    {
        // Core defaults MAIL_HOST when absent, so check presence, not value.
        return self::envPresent('MAIL_HOST')
            && filter_var(trim((string) Env::get('MAIL_FROM_ADDRESS', '')), FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Copy legacy MAIL_SMTP_xxx / MAIL_FROM names to the core MAIL_xxx names
     * core's SmtpMailProvider reads. Explicitly-set core keys always win;
     * bridged keys are tracked so reset() can remove them (test isolation).
     */
    private static function bridgeEnv(): void
    {
        $encryption = strtolower(trim((string) Env::get('MAIL_SMTP_ENCRYPTION', 'tls')));
        $map = [
            'MAIL_HOST' => trim((string) Env::get('MAIL_SMTP_HOST', '')),
            'MAIL_PORT' => trim((string) Env::get('MAIL_SMTP_PORT', '')),
            'MAIL_USERNAME' => trim((string) Env::get('MAIL_SMTP_USER', '')),
            'MAIL_PASSWORD' => (string) (Env::get('MAIL_SMTP_PASS', '') ?? ''),
            'MAIL_FROM_ADDRESS' => trim((string) Env::get('MAIL_FROM', '')),
            'MAIL_FROM_NAME' => trim((string) Env::get('MAIL_FROM_NAME', '')),
            'MAIL_ENCRYPTION' => match ($encryption) {
                'ssl' => 'ssl',
                'none' => '',
                default => 'tls',
            },
        ];
        foreach ($map as $key => $value) {
            if ($value === '' || self::envPresent($key) || in_array($key, self::$bridged, true)) continue;
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            self::$bridged[] = $key;
        }
    }

    private static function envPresent(string $key): bool
    {
        return array_key_exists($key, $_ENV) || getenv($key) !== false;
    }
}
