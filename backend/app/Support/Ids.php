<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 26-char hex IDs for CHAR(26) primary keys (spec docs/20 ERD).
 */
final class Ids
{
    public static function new(): string
    {
        return bin2hex(random_bytes(13));
    }

    public static function orderNumber(): string
    {
        return 'SC-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
    }
}
