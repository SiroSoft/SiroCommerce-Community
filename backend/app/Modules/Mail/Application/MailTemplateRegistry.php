<?php

declare(strict_types=1);

namespace App\Modules\Mail\Application;

use App\Support\Mailer;

/**
 * Extension mail-template registry (Batch 1 Platform §2.4).
 *
 * Extensions send mail by event through this registry only — never by
 * hardcoding another template into OrderNotificationListener (core).
 * An extension registers `event → template` in its boot():
 *
 *   MailTemplateRegistry::register('email_capture.subscribed', 'welcome', ['coupon_code']);
 *
 * Templates are text-only, rendered here and sent through the existing
 * provider via Mailer::send() (no HTML in batch 1, per Mailer.php).
 * Only built-in templates can be registered today; a custom template body
 * is out of scope for batch 1 (both batch-1 plugins use the two templates
 * below). Registering an unknown template key fails fast.
 */
final class MailTemplateRegistry
{
    /** @var array<string, array{template: string, variables: list<string>}> */
    private static array $bindings = [];

    /** @var array<string, array{variables: list<string>}> */
    private static array $templates = [];

    private static bool $defaultsRegistered = false;

    /**
     * @param list<mixed> $variables Variable names the template accepts.
     */
    public static function register(string $event, string $templateKey, array $variables): void
    {
        self::ensureDefaults();
        if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $event)) throw new \InvalidArgumentException('Mail event name is invalid: ' . $event);
        if (!isset(self::$templates[$templateKey])) throw new \InvalidArgumentException('Unknown mail template: ' . $templateKey);
        $names = [];
        foreach ($variables as $variable) {
            if (!is_string($variable) || preg_match('/^[a-z][a-z0-9_]*$/', $variable) !== 1) throw new \InvalidArgumentException('Mail template variable is invalid');
            $names[] = $variable;
        }
        self::$bindings[$event] = ['template' => $templateKey, 'variables' => array_values(array_unique($names))];
    }

    public static function templateFor(string $event): ?string
    {
        self::ensureDefaults();
        return self::$bindings[$event]['template'] ?? null;
    }

    /** @return list<string> */
    public static function variablesFor(string $templateKey): array
    {
        self::ensureDefaults();
        return self::$templates[$templateKey]['variables'] ?? [];
    }

    /** @return array<string, array{template: string, variables: list<string>}> */
    public static function all(): array
    {
        self::ensureDefaults();
        return self::$bindings;
    }

    /**
     * @param array<string, mixed> $variables
     * @return array{subject: string, body: string}
     */
    public static function render(string $templateKey, array $variables): array
    {
        self::ensureDefaults();
        if (!isset(self::$templates[$templateKey])) throw new \InvalidArgumentException('Unknown mail template: ' . $templateKey);
        return match ($templateKey) {
            'abandoned_cart' => self::renderAbandonedCart($variables),
            'welcome' => self::renderWelcome($variables),
            default => throw new \InvalidArgumentException('Unknown mail template: ' . $templateKey),
        };
    }

    /**
     * Resolve the event's template, render it and send it text-only.
     * Returns false when no template is bound to the event (nothing sent)
     * or when delivery fails; never throws.
     * @param array<string, mixed> $variables
     */
    public static function send(string $event, string $to, array $variables): bool
    {
        $template = self::templateFor($event);
        if ($template === null) return false;
        try {
            $rendered = self::render($template, $variables);
        } catch (\InvalidArgumentException) {
            return false;
        }
        return Mailer::send($to, $rendered['subject'], $rendered['body']);
    }

    /** Test isolation: clears bindings and re-registers defaults on next use. */
    public static function reset(): void
    {
        self::$bindings = [];
        self::$templates = [];
        self::$defaultsRegistered = false;
    }

    private static function ensureDefaults(): void
    {
        if (self::$defaultsRegistered) return;
        self::$defaultsRegistered = true;
        self::$templates['abandoned_cart'] = ['variables' => ['customer_name', 'cart_url', 'lines', 'coupon_code']];
        self::$templates['welcome'] = ['variables' => ['customer_name', 'coupon_code']];
        self::$bindings['cart.abandoned'] = ['template' => 'abandoned_cart', 'variables' => ['customer_name', 'cart_url', 'lines', 'coupon_code']];
    }

    /**
     * @param array<string, mixed> $variables
     * @return array{subject: string, body: string}
     */
    private static function renderAbandonedCart(array $variables): array
    {
        $name = self::text($variables, 'customer_name', 'there');
        $url = self::text($variables, 'cart_url', '');
        $coupon = self::text($variables, 'coupon_code', '');
        $lines = $variables['lines'] ?? [];
        $items = [];
        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (!is_array($line)) continue;
                $itemName = self::text($line, 'name', '');
                if ($itemName === '') continue;
                $qtyRaw = $line['qty'] ?? 1;
                $qty = is_numeric($qtyRaw) ? (int) $qtyRaw : 1;
                $items[] = '- ' . $itemName . ($qty > 1 ? ' x ' . $qty : '');
            }
        }
        $body = 'Hi ' . $name . ",\n\n";
        $body .= "You left items in your cart:\n";
        $body .= $items === [] ? "- Your cart is waiting for you.\n" : implode("\n", $items) . "\n";
        if ($url !== '') $body .= "\nComplete your purchase here: " . $url . "\n";
        if ($coupon !== '') $body .= "\nUse coupon " . $coupon . " at checkout for a discount.\n";
        $body .= "\nThanks for shopping with SiroCommerce.";
        return ['subject' => 'Your cart is waiting for you', 'body' => $body];
    }

    /**
     * @param array<string, mixed> $variables
     * @return array{subject: string, body: string}
     */
    private static function renderWelcome(array $variables): array
    {
        $name = self::text($variables, 'customer_name', 'there');
        $coupon = self::text($variables, 'coupon_code', '');
        $body = 'Hi ' . $name . ",\n\nThanks for subscribing to SiroCommerce!\n";
        if ($coupon !== '') $body .= "\nHere is your welcome coupon: " . $coupon . "\nUse it at checkout before it expires.\n";
        $body .= "\nWelcome aboard.";
        return ['subject' => 'Welcome to SiroCommerce', 'body' => $body];
    }

    /**
     * @param array<mixed, mixed> $row
     */
    private static function text(array $row, string $key, string $fallback): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) && !is_numeric($value)) return $fallback;
        $trimmed = trim((string) $value);
        return $trimmed === '' ? $fallback : $trimmed;
    }
}
