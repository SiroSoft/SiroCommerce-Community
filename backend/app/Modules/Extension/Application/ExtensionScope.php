<?php

declare(strict_types=1);

namespace App\Modules\Extension\Application;

use App\Support\CommerceScope;
use App\Support\CommerceScopeException;

/**
 * Canonical store/channel scope for extension-owned ext_* tables.
 *
 * Every extension table is scoped by (store_id, channel_id) unless its manifest
 * explicitly declares global=true and the review accepts that exception. This
 * helper keeps column names, WHERE fragments, bound parameters, row asserts and
 * writes on the same contract so a missed filter fails loudly instead of
 * leaking rows across stores or channels.
 */
final class ExtensionScope
{
    /** @var array{store_id: string, channel_id: string} */
    public const COLUMNS = ['store_id' => 'store_id', 'channel_id' => 'channel_id'];

    /**
     * Column names that every scoped ext_* table must carry.
     *
     * @return array{store_id: string, channel_id: string}
     */
    public static function columns(): array
    {
        return self::COLUMNS;
    }

    /**
     * Extract the persistence scope from the request scope. Only the store and
     * channel dimensions are persisted; locale/currency are presentation facts.
     *
     * @return array{store_id: string, channel_id: string}
     */
    public static function fromScope(CommerceScope $scope): array
    {
        $storeId = trim($scope->storeId);
        $channelId = trim($scope->channelId);
        if ($storeId === '') {
            throw new CommerceScopeException('Extension scope requires a non-empty store_id.');
        }
        if ($channelId === '') {
            throw new CommerceScopeException('Extension scope requires a non-empty channel_id.');
        }

        return ['store_id' => $scope->storeId, 'channel_id' => $scope->channelId];
    }

    /**
     * Deterministic WHERE fragment and bound parameters for scoped queries.
     *
     * Append extension predicates after this fragment, e.g.
     * WHERE store_id = ? AND channel_id = ? AND state = ?
     * with array_merge($params, [$state]).
     *
     * @param array<string, mixed> $scope
     * @return array{0: string, 1: list<string>}
     */
    public static function where(array $scope): array
    {
        $storeId = $scope['store_id'] ?? null;
        $channelId = $scope['channel_id'] ?? null;
        if (!is_string($storeId) || trim($storeId) === '') {
            throw new CommerceScopeException('Extension scope requires a non-empty store_id.');
        }
        if (!is_string($channelId) || trim($channelId) === '') {
            throw new CommerceScopeException('Extension scope requires a non-empty channel_id.');
        }

        return ['store_id = ? AND channel_id = ?', [$storeId, $channelId]];
    }

    /**
     * Inject the request scope into a write payload.
     *
     * A payload that already carries store_id/channel_id must match the request
     * scope exactly; a mismatch is rejected rather than silently overwritten so
     * cross-store writes cannot be laundered through a merge.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function merge(array $data, CommerceScope $scope): array
    {
        $expected = self::fromScope($scope);
        foreach ($expected as $column => $value) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $actual = $data[$column];
            if (!is_string($actual) || trim($actual) === '') {
                throw new CommerceScopeException('Extension data ' . $column . ' must be a non-empty string.');
            }
            if ($actual !== $value) {
                throw new CommerceScopeException('Extension data ' . $column . ' does not match the request scope.');
            }
        }
        foreach ($expected as $column => $value) {
            $data[$column] = $value;
        }

        return $data;
    }

    /**
     * Fail closed when a row read from an ext_* table is missing scope or
     * belongs to another store/channel.
     *
     * @param array<string, mixed> $row
     */
    public static function assertScoped(array $row, CommerceScope $scope): void
    {
        $expected = self::fromScope($scope);
        foreach ($expected as $column => $value) {
            $actual = $row[$column] ?? null;
            if (!is_string($actual) || trim($actual) === '') {
                throw new CommerceScopeException('Extension row is missing ' . $column . '.');
            }
            if ($actual !== $value) {
                throw new CommerceScopeException('Extension row ' . $column . ' does not match the request scope.');
            }
        }
    }

    /**
     * A manifest may opt out of scoping only with an explicit boolean global
     * flag. Anything else (missing, string "true", 1) stays scoped.
     *
     * @param array<string, mixed> $manifest
     */
    public static function isGlobal(array $manifest): bool
    {
        return ($manifest['global'] ?? false) === true;
    }
}
