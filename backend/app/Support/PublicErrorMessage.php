<?php

declare(strict_types=1);

namespace App\Support;

/** Removes infrastructure/provider details from client-visible error messages. */
final class PublicErrorMessage
{
    public static function from(string $message, string $fallback = 'Request could not be completed'): string
    {
        $sensitive = '/(?:sql|pdo|database|redis|connection|credential|secret|token|api.?key|provider|stripe|paypal|adyen|dhl|authorization|webhook|socket|timeout|curl|file_get_contents)/i';
        return $message === '' || preg_match($sensitive, $message) === 1 ? $fallback : $message;
    }
}
