<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\BookingSlot;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class BookingController extends Controller
{
    public function slots(Request $request): Response
    {
        $variantId = Arr::requiredParam($request, 'id');
        $rows = BookingSlot::query()->where('variant_id', $variantId)->orderBy('starts_at', 'asc')->get();
        $out = [];
        foreach ($rows as $r) $out[] = Arr::row($r);
        return $this->success($out, 'Booking slots');
    }

    public function quote(Request $request): Response
    {
        $productId = Arr::requiredParam($request, 'id');
        $slotStart = trim((string) $request->query('slot_start', ''));
        if ($slotStart === '' || strtotime($slotStart) === false) return $this->error('slot_start is required (ISO8601)', 422);
        $norm = date('Y-m-d H:i:s', strtotime($slotStart));
        // Find variant for product (first variant)
        $variant = \App\Models\ProductVariant::query()->where('product_id', $productId)->orderBy('id', 'asc')->first();
        if ($variant === null) return $this->error('Product variant not found', 404);
        $variantId = Arr::str(Arr::row($variant), 'id');
        $slot = BookingSlot::query()->where('variant_id', $variantId)->where('starts_at', $norm)->first();
        if ($slot === null) {
            // try any variant of product
            $variantIds = array_values(array_filter(array_map(fn($v) => Arr::str(Arr::row($v), 'id'), \App\Models\ProductVariant::query()->where('product_id', $productId)->get()), fn($id) => $id !== ''));
            $slot = BookingSlot::query()->whereIn('variant_id', $variantIds)->where('starts_at', $norm)->first();
            if ($slot !== null) $variantId = Arr::str(Arr::row($slot), 'variant_id');
        }
        if ($slot === null) return $this->error('Booking slot not found', 404);
        $slotRow = Arr::row($slot);
        $capacity = Arr::int($slotRow, 'capacity');
        $reserved = 0;
        foreach (\App\Models\BookingReservation::query()->where('variant_id', $variantId)->where('slot_start', $norm)->whereIn('state', ['reserved','committed'])->get() as $r) $reserved += Arr::int(Arr::row($r), 'qty');
        // cart lines not counted here (preview)
        $remaining = max(0, $capacity - $reserved);
        $priceMinor = Arr::int($slotRow, 'price_minor', -1);
        if ($priceMinor < 0) {
            $priceMinor = Arr::int(Arr::row($variant), 'price_minor', 0);
        }
        $currency = Arr::str($slotRow, 'currency', Arr::str(Arr::row($variant), 'currency', 'VND')) ?: 'VND';
        return $this->success(['variant_id' => $variantId, 'slot' => $slotRow, 'price_minor' => $priceMinor, 'currency' => $currency, 'capacity' => $capacity, 'reserved' => $reserved, 'remaining' => $remaining], 'Booking quote');
    }

    public function storeSlot(Request $request): Response
    {
        $variantId = Arr::requiredParam($request, 'id');
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'starts_at' => 'required|string',
            'ends_at' => 'required|string',
            'capacity' => 'required|integer',
            'price_minor' => 'nullable|integer',
            'currency' => 'nullable|string',
        ]);
        $starts = Arr::requiredStr($data, 'starts_at');
        $ends = Arr::requiredStr($data, 'ends_at');
        if (strtotime($starts) === false || strtotime($ends) === false || strtotime($starts) >= strtotime($ends)) return $this->error('Invalid slot window', 422);
        $capacity = Arr::int($data, 'capacity');
        if ($capacity < 1 || $capacity > 1000) return $this->error('Capacity must be 1-1000', 422);
        // overlap check
        foreach (BookingSlot::query()->where('variant_id', $variantId)->get() as $s) {
            $row = Arr::row($s);
            $a = strtotime(Arr::str($row, 'starts_at'));
            $b = strtotime(Arr::str($row, 'ends_at'));
            $ns = strtotime($starts);
            $ne = strtotime($ends);
            if ($ns < $b && $ne > $a) return $this->error('Slot overlaps existing', 422);
        }
        $price = Arr::int($data, 'price_minor', -1);
        if ($price !== -1 && $price < 0) return $this->error('Invalid price', 422);
        $currency = Arr::str($data, 'currency');
        if ($currency !== '' && !preg_match('/^[A-Z]{3}$/i', $currency)) return $this->error('Invalid currency', 422);
        $newId = Ids::new();
        $payload = [
            'id' => $newId,
            'variant_id' => $variantId,
            'starts_at' => date('Y-m-d H:i:s', strtotime($starts)),
            'ends_at' => date('Y-m-d H:i:s', strtotime($ends)),
            'capacity' => $capacity,
            'price_minor' => $price === -1 ? null : $price,
            'currency' => $currency !== '' ? strtoupper($currency) : null,
        ];
        \Siro\Core\Database::table('booking_slots')->insert($payload);
        $row = BookingSlot::query()->where('id', $newId)->first();
        $out = $row ? Arr::row($row) : $payload;
        return $this->created($out, 'Slot created');
    }
}
