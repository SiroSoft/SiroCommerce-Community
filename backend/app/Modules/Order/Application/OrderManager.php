<?php
declare(strict_types=1);
namespace App\Modules\Order\Application;

use App\Modules\Order\Contracts\OrderCommandPort;
use App\Modules\Checkout\Contracts\OrderStatePort;
use App\Modules\Promotion\Contracts\PromotionUsageReconciler;
use App\Modules\Order\Domain\OrderStateMachine;
use App\Modules\Shared\Hooks\HookName;
use App\Modules\Shared\Hooks\Hooks;
use App\Support\Arr;
use Siro\Core\Audit;
use Siro\Core\Event;
use Siro\Core\Metrics;

final class OrderManager implements OrderStatePort
{
    public function __construct(private readonly OrderCommandPort $orders, private readonly ?PromotionUsageReconciler $promotionUsage = null) {}
    /** @return array<string, mixed> */
    public function transition(string $id, string $to, string $actor, string $reason = ''): array
    {
        $order = $this->orders->find($id);
        if ($order === null) throw new \RuntimeException('Order not found');
        $from = Arr::requiredStr($order, 'state');
        OrderStateMachine::assert($from, $to);
        // Modules may veto the transition (fraud hold, compliance); the reason
        // surfaces to the caller and nothing is persisted on veto.
        Hooks::fire(HookName::OrderBeforeTransition, ['order_id' => $id, 'from' => $from, 'to' => $to, 'actor' => $actor, 'reason' => $reason]);
        if ($to === 'cancelled' && $this->promotionUsage !== null) {
            $this->promotionUsage->reconcile($id, Arr::int($order, 'grand_total_minor'), Arr::int($order, 'grand_total_minor'), Arr::str($order, 'currency', 'VND'), 'order-cancel:' . $id, 'order_cancelled');
        }
        $this->orders->setState($id, $to);
        $this->orders->logTransition($id, $from, $to, $actor, $reason);
        $updated = $this->orders->find($id);
        if ($updated === null) throw new \RuntimeException('Order lost after transition');
        Event::emit('order.transitioned', ['order_id' => $id, 'from' => $from, 'to' => $to]);
        if ($to === 'delivered' || $to === 'picked_up') Hooks::notify(HookName::OrderDelivered, ['order_id' => $id, 'from' => $from]);
        Audit::log('commerce.order.transition', ['order_id' => $id, 'from' => $from, 'to' => $to, 'actor' => $actor]);
        Metrics::counter('commerce_order_transitions_total', 1, ['to' => $to]);
        return $updated;
    }
}
