<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Reservation;
use App\Models\StockLedger;
use App\Modules\Smart\Contracts\SmartReadStore;
use App\Modules\Smart\Contracts\SearchIndex;
use App\Support\Arr;
final class SmartReadStoreAdapter implements SmartReadStore, SearchIndex
{
    /**
     * @param iterable<object> $models
     * @return array<int, array<string, mixed>>
     */
    private function rows(iterable $models): array { $out = []; foreach ($models as $model) $out[] = Arr::row($model); return $out; }
    public function failedOrders(): array { return $this->rows(Order::query()->where('state', 'failed')->get()); }
    public function awaitingPaymentOrders(): array { return $this->rows(Order::query()->where('state', 'awaiting_payment')->get()); }
    public function inventoryItems(): array { return $this->rows(InventoryItem::query()->get()); }
    public function recentLedgers(string $variantId, string $since): array { return $this->rows(StockLedger::query()->where('variant_id', $variantId)->get()); }
    public function committedReservations(string $variantId): array { return $this->rows(Reservation::query()->where('variant_id', $variantId)->where('state', 'committed')->get()); }
    public function variant(string $id): ?array { $row = ProductVariant::query()->where('id', $id)->first(); return $row === null ? null : Arr::row($row); }
    public function products(): array { return $this->rows(Product::query()->get()); }
    public function variantsForProduct(string $productId): array { return $this->rows(ProductVariant::query()->where('product_id', $productId)->get()); }
    public function search(string $query): array { $like = '%' . trim($query) . '%'; $products = []; foreach (Product::query()->whereRaw('name LIKE ?', [$like])->limit(10)->get() as $m) { $r = Arr::row($m); $products[] = ['id' => Arr::str($r, 'id'), 'name' => Arr::str($r, 'name'), 'slug' => Arr::str($r, 'slug')]; } $orders = []; foreach (Order::query()->whereRaw('number LIKE ?', [$like])->limit(10)->get() as $m) { $r = Arr::row($m); $orders[] = ['id' => Arr::str($r, 'id'), 'number' => Arr::str($r, 'number'), 'state' => Arr::str($r, 'state')]; } $customers = []; foreach (Customer::query()->whereRaw('email LIKE ?', [$like])->limit(10)->get() as $m) { $r = Arr::row($m); $customers[] = ['id' => Arr::str($r, 'id'), 'email' => Arr::str($r, 'email'), 'name' => Arr::str($r, 'name')]; } return ['products' => $products, 'orders' => $orders, 'customers' => $customers, 'commands' => [], 'facets' => ['types' => ['product' => count($products), 'order' => count($orders), 'customer' => count($customers)]]]; }
    /** @return array{mode: string, lag_seconds: int, stale: bool} */
    public function status(): array { return ['mode' => 'read-through', 'lag_seconds' => 0, 'stale' => false]; }
    public function orderByNumberOrId(string $value): ?array { $row = Order::query()->where('number', $value)->first() ?? Order::find($value); return $row === null ? null : Arr::row($row); }
}
