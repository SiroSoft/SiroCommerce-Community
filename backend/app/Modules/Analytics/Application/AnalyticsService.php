<?php
declare(strict_types=1);
namespace App\Modules\Analytics\Application;
use App\Modules\Analytics\Contracts\AnalyticsStore;
use App\Modules\Analytics\Domain\FunnelResult;
use App\Modules\Analytics\Contracts\AnalyticsReader;
use App\Modules\Analytics\Contracts\AnalyticsExporter;
use App\Modules\Analytics\Domain\AnalyticsEventSchema;
use App\Modules\Analytics\Domain\AnalyticsRedactor;
use App\Support\Ids;
final class AnalyticsService
{
    public const VIEW = 'product.view'; public const ADD = 'cart.add'; public const CHECKOUT = 'checkout.start'; public const PAYMENT = 'payment.done'; public const ORDER = 'order.placed';
    public function __construct(private readonly AnalyticsStore $store, private readonly ?AnalyticsReader $reader = null, private readonly ?AnalyticsExporter $exporter = null) {}
    /** @return array<string, mixed> */
    public function track(string $event, ?string $type = null, ?string $id = null, ?string $session = null, int $valueMinor = 0, ?string $storeId = null, ?string $channelId = null): array { return $this->record($event, ['entity_type' => $type, 'entity_id' => $id, 'session_key' => $session, 'value_minor' => $valueMinor, 'store_id' => $storeId, 'channel_id' => $channelId], Ids::new()); }
    /** @param array<string, mixed> $properties */
    public function trackStorefront(string $event, array $properties, string $eventId, string $session, string $storeId, string $channelId, string $locale, string $currency): array
    {
        $safeProperties = AnalyticsEventSchema::validateStorefrontPayload($event, $properties);
        return $this->record($event, ['properties' => $safeProperties, 'session_key' => $session, 'store_id' => $storeId, 'channel_id' => $channelId, 'locale' => $locale, 'currency' => $currency], $eventId);
    }
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function record(string $event, array $payload, ?string $eventId = null, int $schemaVersion = AnalyticsEventSchema::VERSION): array
    {
        if (!AnalyticsEventSchema::accepts($event, $schemaVersion)) throw new \InvalidArgumentException('Unsupported analytics event schema');
        $safe = AnalyticsRedactor::redact($payload);
        $eventId ??= substr(hash('sha256', $event . ':' . json_encode($safe, JSON_THROW_ON_ERROR)), 0, 26);
        $session = $safe['session_key'] ?? null;
        return $this->store->record(['id' => Ids::new(), 'event_id' => $eventId, 'event' => $event, 'schema_version' => $schemaVersion, 'payload_json' => json_encode($safe, JSON_THROW_ON_ERROR), 'entity_type' => $safe['entity_type'] ?? null, 'entity_id' => $safe['entity_id'] ?? null, 'session_key' => is_scalar($session) ? hash('sha256', (string) $session) : null, 'value_minor' => is_numeric($safe['value_minor'] ?? null) ? (int) $safe['value_minor'] : 0, 'store_id' => is_string($safe['store_id'] ?? null) ? $safe['store_id'] : null, 'channel_id' => is_string($safe['channel_id'] ?? null) ? $safe['channel_id'] : null, 'locale' => is_string($safe['locale'] ?? null) ? $safe['locale'] : null, 'currency' => is_string($safe['currency'] ?? null) ? $safe['currency'] : null]);
    }
    public function isAllowed(string $event): bool { return AnalyticsEventSchema::accepts($event); }
    /** @return array{data: array<int, array<string, mixed>>, meta: array<string, int>} */
    public function read(string $from, string $to, ?string $event, int $page, int $perPage, ?string $storeId = null, ?string $channelId = null): array { if ($this->reader === null) throw new \LogicException('Analytics reader is not configured'); return $this->reader->read($from, $to, $event, $page, $perPage, $storeId, $channelId); }
    /** @return array{schema_version: int, exported_at: string, records: array<int, array<string, mixed>>} */
    public function export(string $from, string $to, ?string $event, int $page, int $perPage): array { if ($this->exporter === null) throw new \LogicException('Analytics exporter is not configured'); return $this->exporter->export($from, $to, $event, $page, $perPage); }
    public function funnel(): FunnelResult
    {
        $counts = []; foreach ([self::VIEW, self::ADD, self::CHECKOUT, self::PAYMENT, self::ORDER, 'product_view', 'wishlist_add', 'wishlist_remove', 'search_submit', 'checkout_started'] as $stage) $counts[$stage] = $this->store->count($stage);
        $viewCount = $counts[self::VIEW] + $counts['product_view'];
        $addCount = $counts[self::ADD] + $counts['wishlist_add'];
        $checkoutCount = $counts[self::CHECKOUT] + $counts['checkout_started'];
        $rate = static fn (int $part, int $whole): float => $whole > 0 ? round($part / $whole, 4) : 0.0;
        $rates = ['view_to_cart' => $rate($addCount, $viewCount), 'cart_to_checkout' => $rate($checkoutCount, $addCount), 'checkout_to_order' => $rate($counts[self::ORDER], $checkoutCount), 'payment_failure' => $rate($checkoutCount - $counts[self::PAYMENT], $checkoutCount)];
        $anomalies = []; if ($checkoutCount > 10 && $rates['checkout_to_order'] < 0.1) $anomalies[] = 'checkout abandonment above threshold — inspect traces for payment failures'; if ($addCount > 10 && $rates['view_to_cart'] < 0.05) $anomalies[] = 'add-to-cart rate below threshold — review pricing and stock signals';
        return new FunnelResult($counts, $rates, $anomalies);
    }
}
