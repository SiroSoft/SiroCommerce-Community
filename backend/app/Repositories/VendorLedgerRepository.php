<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\VendorLedgerEntry;
use App\Models\VendorPayout;
use App\Support\Arr;

final class VendorLedgerRepository
{
    /** @return array<string, mixed>|null */
    public function findByIdempotency(string $key): ?array
    {
        $row = VendorLedgerEntry::query()->where('idempotency_key', $key)->first();
        return $row === null ? null : Arr::row($row);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createEntry(array $data): array
    {
        return VendorLedgerEntry::create($data)->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function entries(string $vendorId, ?string $state, ?string $from, ?string $to): array
    {
        $query = VendorLedgerEntry::query()->where('vendor_id', $vendorId);
        if ($state !== null && $state !== '') $query->where('state', $state);
        if ($from !== null && $from !== '') $query->whereDate('created_at', '>=', $from);
        if ($to !== null && $to !== '') $query->whereDate('created_at', '<=', $to);
        return array_map(static fn ($row): array => Arr::row($row), $query->orderBy('created_at', 'asc')->orderBy('id', 'asc')->get());
    }

    /**
     * @return array{gross: int, commission: int, net: int, pending: int, available: int, paid: int}
     */
    public function totals(string $vendorId): array
    {
        $totals = ['gross' => 0, 'commission' => 0, 'net' => 0, 'pending' => 0, 'available' => 0, 'paid' => 0];
        foreach (VendorLedgerEntry::query()->where('vendor_id', $vendorId)->get() as $row) {
            $entry = Arr::row($row);
            if (Arr::str($entry, 'state') === 'void') continue;
            $totals['gross'] += Arr::int($entry, 'gross_minor');
            $totals['commission'] += Arr::int($entry, 'commission_minor');
            $totals['net'] += Arr::int($entry, 'net_minor');
            $state = Arr::str($entry, 'state');
            if (array_key_exists($state, $totals)) $totals[$state] += Arr::int($entry, 'net_minor');
        }
        return $totals;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function availableInWindow(string $vendorId, string $from, string $to): array
    {
        $query = VendorLedgerEntry::query()
            ->where('vendor_id', $vendorId)
            ->where('state', 'available')
            ->whereNull('payout_id')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc');
        $query->lockForUpdate();
        return array_map(static fn ($row): array => Arr::row($row), $query->get());
    }

    public function hasSaleForOrder(string $orderId): bool
    {
        return VendorLedgerEntry::query()->where('order_id', $orderId)->where('type', 'sale')->first() !== null;
    }

    /** @return array<int, array<string, mixed>> */
    public function entriesByPayout(string $payoutId): array
    {
        return array_map(
            static fn ($row): array => Arr::row($row),
            VendorLedgerEntry::query()->where('payout_id', $payoutId)->orderBy('id')->get()
        );
    }

    /** @param array<int, string> $entryIds */
    public function assignEntries(array $entryIds, string $payoutId, string $state): void
    {
        if ($entryIds === []) return;
        VendorLedgerEntry::query()->whereIn('id', $entryIds)->update(['payout_id' => $payoutId, 'state' => $state]);
    }

    /** @param array<int, string> $entryIds */
    public function releaseEntries(array $entryIds): void
    {
        if ($entryIds === []) return;
        VendorLedgerEntry::query()->whereIn('id', $entryIds)->update(['payout_id' => null, 'state' => 'available']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function payouts(string $vendorId): array
    {
        return array_map(
            static fn ($row): array => Arr::row($row),
            VendorPayout::query()->where('vendor_id', $vendorId)->orderBy('created_at', 'desc')->orderBy('id', 'desc')->get()
        );
    }

    /** @return array<string, mixed>|null */
    public function findPayout(string $id): ?array
    {
        $row = VendorPayout::find($id);
        return $row === null ? null : Arr::row($row);
    }

    /** @return array<string, mixed>|null */
    public function findPayoutByIdempotency(string $key): ?array
    {
        $row = VendorPayout::query()->where('idempotency_key', $key)->first();
        return $row === null ? null : Arr::row($row);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createPayout(array $data): array
    {
        return VendorPayout::create($data)->toArray();
    }

    /** @param array<string, mixed> $data */
    public function updatePayout(string $id, array $data): void
    {
        $row = VendorPayout::find($id);
        if ($row !== null) $row->update($data);
    }

    /** @param array<int, string> $entryIds
     * @return array<int, array<string, mixed>>
     */
    public function entriesForPayout(array $entryIds): array
    {
        if ($entryIds === []) return [];
        return array_map(
            static fn ($row): array => Arr::row($row),
            VendorLedgerEntry::query()->whereIn('id', $entryIds)->orderBy('id')->get()
        );
    }
}
