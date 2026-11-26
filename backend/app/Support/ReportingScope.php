<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Channel;
use App\Models\Store;

final class ReportingScope
{
    /** @return array{0:?string,1:?string} */
    public static function validate(?string $storeId, ?string $channelId): array
    {
        $storeId = self::normalize($storeId);
        $channelId = self::normalize($channelId);
        if (($storeId === null) !== ($channelId === null)) {
            throw new \InvalidArgumentException('store_id and channel_id must be provided together');
        }
        if ($storeId === null) return [null, null];
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $storeId) !== 1 || $channelId === null || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $channelId) !== 1) {
            throw new \InvalidArgumentException('Invalid reporting scope');
        }
        if (Store::find($storeId) === null || Channel::query()->where('id', $channelId)->where('store_id', $storeId)->first() === null) {
            throw new \InvalidArgumentException('Reporting scope not found');
        }
        return [$storeId, $channelId];
    }

    private static function normalize(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);
        return $value === null || $value === '' ? null : $value;
    }
}
