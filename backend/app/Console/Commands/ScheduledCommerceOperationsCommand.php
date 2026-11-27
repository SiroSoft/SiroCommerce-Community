<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ReturnLabelJob;
use App\Models\Cart;
use App\Models\CartLine;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\ReturnRequest;
use App\Models\Shipment;
use App\Modules\Checkout\Contracts\OrderStatePort;
use App\Modules\Cart\Application\CartService;
use App\Modules\MarketingAutomation\Application\MarketingAutomationService;
use App\Modules\Payment\Application\PaymentReconciliationService;
use App\Modules\Payment\Contracts\PaymentReconciliationProvider;
use App\Modules\Payment\Contracts\PaymentReconciliationStore;
use App\Modules\Platform\Infrastructure\OutboxDispatcher;
use App\Modules\Platform\Infrastructure\V2SideEffectRepository;
use App\Modules\Webhook\Application\WebhookReceiver;
use App\Modules\Inventory\Application\InventoryManager;
use App\Providers\CommerceServiceProvider;
use App\Support\Arr;
use Siro\Core\App;
use Siro\Core\Audit;
use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;
use Siro\Core\Container;
use Siro\Core\Database;
use Siro\Core\Event;
use Siro\Core\Metrics;
use Siro\Core\Queue;
use App\Modules\Subscriptions\Application\SubscriptionService;

/** Idempotent maintenance pass used by the native Siro scheduler. */
final class ScheduledCommerceOperationsCommand implements CommandInterface
{
    use CommandSupport;

    public string $signature = 'commerce:scheduled';
    public string $description = 'Expire stale commerce state and dispatch recoverable work';

    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $app = new App($this->basePath);
        $app->boot();
        (new CommerceServiceProvider())->register($app);

        $dryRun = !$this->has($args, '--apply') || $this->has($args, '--dry-run');
        $minutes = max(1, (int) $this->option($args, '--minutes=', '30'));
        $limit = max(1, (int) $this->option($args, '--limit=', '100'));
        Metrics::init('sirocommerce', true);
        Audit::setBasePath($this->basePath);

        if (!$this->acquireLock()) {
            $this->write(json_encode(['dry_run' => $dryRun, 'locked' => true], JSON_THROW_ON_ERROR));
            Metrics::counter('commerce_scheduler_runs_total', 1, ['result' => 'locked']);
            Metrics::persistNow();
            return 0;
        }

        $started = microtime(true);
        try {
            $now = date('Y-m-d H:i:s');
            $before = date('Y-m-d H:i:s', time() - ($minutes * 60));
            $result = [
                'dry_run' => $dryRun,
                'locked' => false,
                'orders' => $this->expireOrders($before, $limit, $dryRun),
                // Abandoned-cart scan MUST run before expireCarts: the same
                // --minutes threshold marks carts stale, and eligibility
                // requires state=open, so scanning after expiry would find none.
                'abandoned_carts' => $this->scanAbandonedCarts($before, $limit, $dryRun),
                'carts' => $this->expireCarts($before, $limit, $dryRun),
                'reservations' => $this->expireReservations($now, $limit, $dryRun),
                'promotion_expired' => $this->expirePromotionReservations($limit, $dryRun),
                'promotion_pruned' => $this->prunePromotionAudits($limit, $dryRun),
                'booking_expired' => $this->expireBookingReservations($limit, $dryRun),
                'payments' => $this->recoverPayments($dryRun),
                'subscriptions' => $this->queueSubscriptionRenewals($now, $limit, $dryRun),
                'jobs' => $this->recoverCommerceJobs($limit, $dryRun),
                'outbox' => $this->dispatchOutbox($limit, $dryRun),
                'webhooks' => $this->dispatchWebhooks($limit, $dryRun),
                'return_labels' => $this->dispatchReturnLabels($limit, $dryRun),
                'stuck' => $this->detectStuckOperations($limit),
                'cms' => $this->publishCms($now, $dryRun),
            ];
            Audit::log('commerce.scheduler.run', ['actor' => 'scheduler', 'dry_run' => $dryRun, 'result' => $this->summary($result)]);
            Metrics::counter('commerce_scheduler_runs_total', 1, ['result' => 'completed']);
            Metrics::histogram('commerce_scheduler_duration_ms', (microtime(true) - $started) * 1000);
        } catch (\Throwable $e) {
            if ($dryRun && (str_contains(strtolower($e->getMessage()), 'no such table') || str_contains(strtolower($e->getMessage()), "doesn't exist"))) {
                $result = ['dry_run' => true, 'locked' => false, 'schema_unavailable' => true, 'processed' => 0, 'skipped' => 0, 'failed' => 0];
                Audit::log('commerce.scheduler.schema_unavailable', ['actor' => 'scheduler', 'dry_run' => true, 'error' => $e->getMessage()]);
                Metrics::counter('commerce_scheduler_runs_total', 1, ['result' => 'schema_unavailable']);
                $this->write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                return 0;
            }
            Audit::log('commerce.scheduler.failed', ['actor' => 'scheduler', 'dry_run' => $dryRun, 'error' => $e->getMessage()]);
            Metrics::counter('commerce_scheduler_runs_total', 1, ['result' => 'failed']);
            $this->error($e->getMessage());
            return 1;
        } finally {
            $this->releaseLock();
            Metrics::persistNow();
        }

        $this->write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return 0;
    }

    /** @return array<string, int> */
    private function publishCms(string $now, bool $dryRun): array
    {
        if ($dryRun) return ['published' => 0];
        $store = Container::getInstance()->make(\App\Modules\Cms\Contracts\CmsStore::class);
        if (!$store instanceof \App\Modules\Cms\Contracts\CmsStore) throw new \RuntimeException('CMS store unavailable');
        return ['published' => $store->publishScheduled($now)];
    }

    /** @return array<string, mixed> */
    private function expireOrders(string $before, int $limit, bool $dryRun): array
    {
        $state = Container::getInstance()->make(OrderStatePort::class);
        if (!$state instanceof OrderStatePort) throw new \RuntimeException('Order state service unavailable');
        $ids = []; $cancelled = 0; $failed = 0;
        foreach (Order::query()->whereIn('state', ['pending', 'awaiting_payment'])->where('created_at', '<', $before)->limit($limit)->get() as $model) {
            $id = Arr::str(Arr::row($model), 'id');
            if ($id === '') { $failed++; continue; }
            $ids[] = $id;
            if ($dryRun) continue;
            try { $state->transition($id, 'cancelled', 'scheduler', 'payment timeout'); $this->releaseOrderReservations($id); $cancelled++; }
            catch (\Throwable) { $failed++; }
        }
        return ['candidate_ids' => $ids, 'processed' => $cancelled, 'skipped' => $dryRun ? count($ids) : 0, 'failed' => $failed, 'cancelled' => $cancelled];
    }

    /** @return array<string, mixed> */
    private function scanAbandonedCarts(string $before, int $limit, bool $dryRun): array
    {
        $ids = [];
        $scheduled = 0;
        $emitted = 0;
        $failed = 0;
        foreach (Cart::query()->where('state', 'open')->whereNotNull('customer_id')->where('updated_at', '<', $before)->limit($limit)->get() as $model) {
            $cart = Arr::row($model);
            $id = Arr::str($cart, 'id');
            if ($id === '') {
                $failed++;
                continue;
            }
            $ids[] = $id;
            if ($dryRun) continue;
            try {
                $result = (new MarketingAutomationService())->abandonedCart($id);
                if (($result['scheduled'] ?? false) !== true) continue;
                $scheduled++;
                try {
                    Event::emit('cart.abandoned', ['cart_id' => $id, 'customer_id' => Arr::nullableStr($cart, 'customer_id'), 'totals_minor' => $this->abandonedCartSnapshot($id, $cart), 'at' => date('Y-m-d H:i:s')]);
                    $emitted++;
                } catch (\Throwable) {
                    $failed++;
                }
            } catch (\Throwable) {
                $failed++;
            }
        }
        return ['candidate_ids' => $ids, 'processed' => $scheduled, 'skipped' => $dryRun ? count($ids) : 0, 'failed' => $failed, 'scheduled' => $scheduled, 'emitted' => $emitted];
    }

    /**
     * Totals snapshot for the `cart.abandoned` payload. Prefers the priced
     * quote; falls back to raw lines when the cart cannot be quoted (e.g.
     * empty). The Abandoned Cart plugin persists its own snapshot table;
     * the platform only carries the snapshot in the event payload.
     * @param array<string, mixed> $cart
     * @return array<string, mixed>
     */
    private function abandonedCartSnapshot(string $cartId, array $cart): array
    {
        $currency = Arr::str($cart, 'currency', 'VND');
        try {
            $service = Container::getInstance()->make(CartService::class);
            if ($service instanceof CartService) {
                $quote = $service->quote($cartId);
                return ['currency' => $currency, 'subtotal_minor' => Arr::int($quote, 'subtotal_minor'), 'discount_minor' => Arr::int($quote, 'discount_minor'), 'shipping_minor' => Arr::int($quote, 'shipping_minor'), 'tax_minor' => Arr::int($quote, 'tax_minor'), 'total_minor' => Arr::int($quote, 'total_minor'), 'lines' => $quote['lines']];
            }
        } catch (\Throwable) {
            // Fall through to the raw-lines snapshot below.
        }
        $lines = [];
        $subtotal = 0;
        foreach (CartLine::query()->where('cart_id', $cartId)->get() as $line) {
            $row = Arr::row($line);
            $qty = Arr::int($row, 'qty');
            $unit = Arr::int($row, 'unit_price_minor');
            $subtotal += $qty * $unit;
            $variant = ProductVariant::find(Arr::str($row, 'variant_id'));
            $product = $variant === null ? null : Product::find(Arr::str(Arr::row($variant), 'product_id'));
            $lines[] = ['variant_id' => Arr::str($row, 'variant_id'), 'name' => $product === null ? '' : Arr::str(Arr::row($product), 'name'), 'qty' => $qty, 'unit_price_minor' => $unit];
        }
        return ['currency' => $currency, 'subtotal_minor' => $subtotal, 'discount_minor' => 0, 'shipping_minor' => 0, 'tax_minor' => 0, 'total_minor' => $subtotal, 'lines' => $lines];
    }

    /** @return array<string, mixed> */
    private function expireCarts(string $before, int $limit, bool $dryRun): array
    {
        $ids = []; $expired = 0; $failed = 0;
        foreach (Cart::query()->where('state', 'open')->where('updated_at', '<', $before)->limit($limit)->get() as $model) {
            $id = Arr::str(Arr::row($model), 'id');
            if ($id === '') { $failed++; continue; }
            $ids[] = $id;
            if ($dryRun) continue;
            try {
                foreach (Reservation::query()->where('cart_id', $id)->where('state', 'held')->get() as $reservation) {
                    $reservationId = Arr::str(Arr::row($reservation), 'id');
                    if ($reservationId !== '') $this->inventory()->release($reservationId, 'scheduler:cart-expired');
                }
                $model->setAttribute('state', 'expired'); $model->save(); $expired++;
            } catch (\Throwable) { $failed++; }
        }
        return ['candidate_ids' => $ids, 'processed' => $expired, 'skipped' => $dryRun ? count($ids) : 0, 'failed' => $failed, 'expired' => $expired];
    }

    /** @return array<string, mixed> */
    private function expireReservations(string $now, int $limit, bool $dryRun): array
    {
        $ids = []; $released = 0; $failed = 0;
        foreach (Reservation::query()->where('state', 'held')->whereNotNull('expires_at')->where('expires_at', '<=', $now)->limit($limit)->get() as $model) {
            $id = Arr::str(Arr::row($model), 'id');
            if ($id === '') { $failed++; continue; }
            $ids[] = $id;
            if ($dryRun) continue;
            try { $this->inventory()->release($id, 'scheduler:reservation-expired'); $released++; } catch (\Throwable) { $failed++; }
        }
        return ['candidate_ids' => $ids, 'processed' => $released, 'skipped' => $dryRun ? count($ids) : 0, 'failed' => $failed, 'released' => $released];
    }

    /** @return array<string, mixed> */
    private function recoverPayments(bool $dryRun): array
    {
        $store = Container::getInstance()->make(PaymentReconciliationStore::class);
        if (!$store instanceof PaymentReconciliationStore) throw new \RuntimeException('Payment reconciliation store unavailable');
        $rows = $store->unresolved();
        if ($dryRun) return ['inspected' => count($rows), 'processed' => 0, 'skipped' => count($rows), 'failed' => 0, 'resolved' => 0, 'unknown' => count($rows), 'operations' => $rows];
        $service = Container::getInstance()->make(PaymentReconciliationService::class);
        $provider = Container::getInstance()->make(PaymentReconciliationProvider::class);
        if (!$service instanceof PaymentReconciliationService || !$provider instanceof PaymentReconciliationProvider) throw new \RuntimeException('Payment reconciliation bindings unavailable');
        $result = $service->reconcile($provider, true);
        return $result + ['processed' => $result['resolved'], 'skipped' => $result['unknown'], 'failed' => 0];
    }

    /** @return array<string, mixed> */
    private function queueSubscriptionRenewals(string $now, int $limit, bool $dryRun): array
    {
        $service = Container::getInstance()->make(SubscriptionService::class);
        if (!$service instanceof SubscriptionService) throw new \RuntimeException('Subscription service unavailable');
        if ($dryRun) return ['queued' => 0, 'skipped' => 0, 'processed' => 0, 'failed' => 0];
        $result = $service->scheduleRenewals($now, $limit);
        return $result + ['processed' => $result['queued'], 'failed' => 0];
    }

    /** @return array<string, mixed> */
    private function dispatchOutbox(int $limit, bool $dryRun): array
    {
        $result = (new OutboxDispatcher())->dispatch($limit, !$dryRun);
        return $result + ['processed' => $dryRun ? 0 : $result['published'], 'skipped' => $dryRun ? $result['count'] : 0];
    }

    /** @return array<string, mixed> */
    private function recoverCommerceJobs(int $limit, bool $dryRun): array
    {
        if ($dryRun) return ['recovered' => 0, 'processed' => 0, 'skipped' => 0, 'failed' => 0];
        $repository = new V2SideEffectRepository();
        $recovered = $repository->recoverQueuedJobs($limit);
        $pruned = $repository->pruneCompleted(604800, $limit);
        return ['recovered' => $recovered, 'pruned_completed' => $pruned, 'processed' => $recovered + $pruned, 'skipped' => 0, 'failed' => 0];
    }

    /** @return array<string, mixed> */
    private function dispatchWebhooks(int $limit, bool $dryRun): array
    {
        $rows = PaymentWebhookEvent::query()->whereIn('state', ['received', 'failed'])->where('signature_ok', 1)->limit($limit)->get();
        $ids = []; $queued = 0; $failed = 0;
        foreach ($rows as $model) {
            $row = Arr::row($model); $provider = Arr::str($row, 'provider'); $eventId = Arr::str($row, 'event_id'); $body = Arr::str($row, 'payload_json');
            if ($provider === '' || $eventId === '' || $body === '') { $failed++; continue; }
            $ids[] = $eventId;
            if ($dryRun) continue;
            try { $receiver = Container::getInstance()->make(WebhookReceiver::class); if (!$receiver instanceof WebhookReceiver) throw new \RuntimeException('Webhook receiver unavailable'); $receiver->receive($provider, $eventId, $body, true); $queued++; }
            catch (\Throwable) { $failed++; }
        }
        return ['candidate_event_ids' => $ids, 'processed' => $queued, 'skipped' => $dryRun ? count($ids) : 0, 'failed' => $failed, 'queued' => $queued];
    }

    /** @return array<string, mixed> */
    private function dispatchReturnLabels(int $limit, bool $dryRun): array
    {
        $rows = ReturnRequest::query()->whereIn('label_state', ['queued', 'failed'])->limit($limit)->get();
        $ids = []; $queued = 0; $skipped = 0;
        foreach ($rows as $model) {
            $row = Arr::row($model); $returnId = Arr::str($row, 'id'); $key = Arr::str($row, 'label_idempotency_key');
            if ($returnId === '' || $key === '') { $skipped++; continue; }
            $ids[] = $returnId;
            if ($dryRun) continue;
            if ($this->returnLabelAlreadyQueued($returnId, $key)) { $skipped++; continue; }
            Queue::push(ReturnLabelJob::class, ['return_id' => $returnId, 'shipping' => [], 'idempotency_key' => $key], 0, 0, 5); $queued++;
        }
        return ['candidate_return_ids' => $ids, 'processed' => $queued, 'skipped' => $dryRun ? count($ids) : $skipped, 'failed' => 0, 'queued' => $queued];
    }

    /** @return array<string, mixed> */
    private function expirePromotionReservations(int $limit, bool $dryRun): array
    {
        $before = date('Y-m-d H:i:s', time() - 900); // 15m
        $ids = []; $expired = 0; $failed = 0;
        try {
            foreach (\App\Models\CouponUsage::query()->where('state', 'reserved')->where('created_at', '<', $before)->limit($limit)->get() as $model) {
                $id = Arr::str(Arr::row($model), 'id');
                if ($id === '') { $failed++; continue; }
                $ids[] = $id;
                if ($dryRun) continue;
                try { $model->setAttribute('state', 'rolled_back'); $model->setAttribute('updated_at', date('Y-m-d H:i:s')); $model->save(); $expired++; } catch (\Throwable $e) { $failed++; }
            }
        } catch (\Throwable $e) { return ['candidate_ids' => [], 'processed' => 0, 'skipped' => 0, 'failed' => 1, 'expired' => 0, 'error' => $e->getMessage()]; }
        return ['candidate_ids' => $ids, 'processed' => $expired, 'skipped' => $dryRun ? count($ids) : 0, 'failed' => $failed, 'expired' => $expired];
    }

    /** @return array<string, mixed> */
    private function prunePromotionAudits(int $limit, bool $dryRun): array
    {
        $before = date('Y-m-d H:i:s', time() - 90 * 86400);
        $ids = []; $pruned = 0; $failed = 0;
        try {
            foreach (\App\Models\PromotionDecisionAudit::query()->where('created_at', '<', $before)->limit($limit)->get() as $model) {
                $id = Arr::str(Arr::row($model), 'id');
                if ($id === '') { $failed++; continue; }
                $ids[] = $id;
                if ($dryRun) continue;
                try { $model->delete(); $pruned++; } catch (\Throwable $e) { $failed++; }
            }
        } catch (\Throwable $e) { return ['candidate_ids' => [], 'processed' => 0, 'skipped' => 0, 'failed' => 1, 'pruned' => 0, 'error' => $e->getMessage()]; }
        return ['candidate_ids' => $ids, 'processed' => $pruned, 'skipped' => $dryRun ? count($ids) : 0, 'failed' => $failed, 'pruned' => $pruned];
    }

    /** @return array<string, mixed> */
    private function expireBookingReservations(int $limit, bool $dryRun): array
    {
        $before = date('Y-m-d H:i:s', time() - 900);
        $ids = []; $expired = 0; $failed = 0;
        try {
            foreach (\App\Models\BookingReservation::query()->where('state', 'reserved')->where('created_at', '<', $before)->limit($limit)->get() as $model) {
                $id = Arr::str(Arr::row($model), 'id');
                if ($id === '') { $failed++; continue; }
                $ids[] = $id;
                if ($dryRun) continue;
                try { $model->setAttribute('state', 'rolled_back'); $model->save(); $expired++; } catch (\Throwable $e) { $failed++; }
            }
        } catch (\Throwable $e) { return ['candidate_ids' => [], 'processed' => 0, 'skipped' => 0, 'failed' => 1, 'expired' => 0, 'error' => $e->getMessage()]; }
        return ['candidate_ids' => $ids, 'processed' => $expired, 'skipped' => $dryRun ? count($ids) : 0, 'failed' => $failed, 'expired' => $expired];
    }

    /** @return array<string, mixed> */
    private function detectStuckOperations(int $limit): array
    {
        $groups = [];
        foreach ([Payment::class => ['pending', 'processing'], Refund::class => ['pending', 'processing'], Shipment::class => ['pending', 'processing']] as $modelClass => $states) {
            $ids = [];
            foreach ($modelClass::query()->whereIn('state', $states)->limit($limit)->get() as $model) { $id = Arr::str(Arr::row($model), 'id'); if ($id !== '') $ids[] = $id; }
            $groups[strtolower((new \ReflectionClass($modelClass))->getShortName())] = ['count' => count($ids), 'candidate_ids' => $ids];
        }
        return ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'operations' => $groups];
    }

    private function returnLabelAlreadyQueued(string $returnId, string $key): bool
    {
        foreach (Queue::getFakedJobs() as $job) if ($job['job'] === ReturnLabelJob::class && is_array($job['data']) && Arr::str(Arr::row($job['data']), 'return_id') === $returnId && Arr::str(Arr::row($job['data']), 'idempotency_key') === $key) return true;
        try {
            $statement = Database::connection()->prepare('SELECT data FROM jobs WHERE job = ?'); $statement->execute([ReturnLabelJob::class]);
            foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $data) { $decoded = is_string($data) ? json_decode($data, true) : null; if (is_array($decoded) && Arr::str(Arr::row($decoded), 'return_id') === $returnId && Arr::str(Arr::row($decoded), 'idempotency_key') === $key) return true; }
        } catch (\Throwable) { }
        return false;
    }

    private function acquireLock(): bool
    {
        $dir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $handle = @fopen($dir . DIRECTORY_SEPARATOR . 'commerce-scheduled.lock', 'c');
        if (!is_resource($handle)) return false;
        $this->lockHandle = $handle;
        if (flock($handle, LOCK_EX | LOCK_NB)) return true;
        fclose($handle);
        $this->lockHandle = null;
        return false;
    }

    private function releaseLock(): void
    {
        if (is_resource($this->lockHandle)) { flock($this->lockHandle, LOCK_UN); fclose($this->lockHandle); }
        $this->lockHandle = null;
    }

    private function releaseOrderReservations(string $orderId): void
    { foreach (Reservation::query()->where('order_id', $orderId)->where('state', 'held')->get() as $reservation) { $id = Arr::str(Arr::row($reservation), 'id'); if ($id !== '') $this->inventory()->release($id, 'scheduler:order-expired'); } }

    private function inventory(): InventoryManager
    { $inventory = Container::getInstance()->make(InventoryManager::class); if (!$inventory instanceof InventoryManager) throw new \RuntimeException('Inventory service unavailable'); return $inventory; }

    /**
     * @param array<string, mixed> $result
     * @return array<string, array<string, int>>
     */
    private function summary(array $result): array
    {
        $summary = [];
        foreach ($result as $name => $value) {
            if (!is_array($value)) continue;
            $summary[$name] = [
                'processed' => is_numeric($value['processed'] ?? null) ? (int) $value['processed'] : 0,
                'skipped' => is_numeric($value['skipped'] ?? null) ? (int) $value['skipped'] : 0,
                'failed' => is_numeric($value['failed'] ?? null) ? (int) $value['failed'] : 0,
            ];
        }
        return $summary;
    }

    /** @param array<int, string> $args */
    private function has(array $args, string $value): bool { return in_array($value, $args, true); }

    /** @param array<int, string> $args */
    private function option(array $args, string $prefix, string $default): string { foreach ($args as $arg) if (str_starts_with($arg, $prefix)) return substr($arg, strlen($prefix)); return $default; }
}
