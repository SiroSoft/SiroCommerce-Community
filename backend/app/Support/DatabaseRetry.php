<?php

declare(strict_types=1);

namespace App\Support;

use Siro\Core\Database;

/** Retries only transient InnoDB deadlocks/lock timeouts. */
final class DatabaseRetry
{
    public static function transaction(callable $callback, int $attempts = 3): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return Database::transaction($callback);
            } catch (\Throwable $e) {
                if ($attempt >= $attempts || !self::isTransient($e)) {
                    throw $e;
                }
                usleep(10000 * $attempt);
            }
        }
    }

    private static function isTransient(\Throwable $e): bool
    {
        if ($e instanceof \PDOException) {
            $rawCode = $e->errorInfo[1] ?? $e->getCode();
            $code = is_int($rawCode) || is_string($rawCode) ? (string) $rawCode : '';
            return in_array($code, ['1205', '1213'], true);
        }
        return str_contains(strtolower($e->getMessage()), 'deadlock')
            || str_contains(strtolower($e->getMessage()), 'lock wait timeout');
    }
}
