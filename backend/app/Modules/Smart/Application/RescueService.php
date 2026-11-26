<?php
declare(strict_types=1);
namespace App\Modules\Smart\Application;
use App\Modules\Cart\Contracts\CartCommandPort;
use App\Modules\Inventory\Contracts\InventoryReader;
use App\Modules\Order\Contracts\OrderReader;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Database;
final class RescueService
{
    public function __construct(private readonly OrderReader $orders, private readonly CartCommandPort $carts, private readonly InventoryReader $inventory) {}
    /** @return array<string, mixed> */
    public function rescue(string $orderId, string $actor): array
    {
        $order = $this->orders->find($orderId); if ($order === null) throw new \RuntimeException('Order not found');
        $state = Arr::str($order, 'state'); $lines = $this->orders->lines($orderId); $report = ['order_id' => $orderId, 'detected' => "Order {$orderId} in state {$state}", 'diagnosis' => '', 'safe' => false, 'recommendation' => '', 'recovered_cart_id' => null, 'verified' => false, 'audit' => "rescue checked by {$actor}: no-op"];
        if ($state !== 'failed') { $report['diagnosis'] = "Order is {$state}, not failed — nothing to rescue"; $report['recommendation'] = 'No action required'; return $report; }
        if ($lines === []) { $report['diagnosis'] = 'Failed order has no lines — likely failed before reservation'; $report['recommendation'] = 'Ask customer to retry checkout'; return $report; }
        foreach ($lines as $line) { /** @var array<string, mixed> $line */ if ($this->inventory->available(Arr::str($line, 'variant_id')) < Arr::int($line, 'qty')) { $report['diagnosis'] = 'Stock changed since failure'; $report['recommendation'] = 'Restock first, then rescue again'; return $report; } }
        $cart = Database::transaction(function () use ($order, $lines): array { $cart = $this->carts->create(['id' => Ids::new(), 'customer_id' => Arr::nullableStr($order, 'customer_id'), 'currency' => Arr::str($order, 'currency', 'VND'), 'state' => 'open']); /** @var array<string, mixed> $cart */ $id = Arr::requiredStr($cart, 'id'); foreach ($lines as $line) { /** @var array<string, mixed> $line */ $this->carts->addLine($id, Arr::requiredStr($line, 'variant_id'), Arr::requiredInt($line, 'qty'), Arr::requiredInt($line, 'unit_price_minor')); } return $cart; });
        /** @var array<string, mixed> $cart */
        $cartId = Arr::requiredStr($cart, 'id'); $report['safe'] = true; $report['diagnosis'] = count($lines) . ' lines recoverable, stock available'; $report['recommendation'] = 'Rebuilt a fresh cart from the order snapshot'; $report['recovered_cart_id'] = $cartId; $report['verified'] = count($this->carts->lines($cartId)) === count($lines); $report['audit'] = "rescue by {$actor}: cart {$cartId} rebuilt from order {$orderId}"; return $report;
    }
}
