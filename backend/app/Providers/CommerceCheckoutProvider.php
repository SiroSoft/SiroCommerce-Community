<?php
declare(strict_types=1);
namespace App\Providers;
use App\Modules\Cart\Application\CartService as NativeCartService;
use App\Modules\Cart\Contracts\CartCommandPort;
use App\Modules\Checkout\Application\CheckoutService as ModuleCheckoutService;
use App\Modules\Checkout\Application\PlaceOrderService;
use App\Modules\Checkout\Contracts\CartPort;
use App\Modules\Checkout\Contracts\CheckoutHandler;
use App\Modules\Checkout\Contracts\InventoryPort as CheckoutInventoryPort;
use App\Modules\Checkout\Contracts\OrderPort as CheckoutOrderPort;
use App\Modules\Checkout\Contracts\OrderStatePort;
use App\Modules\Checkout\Contracts\PromotionPort;
use App\Modules\Inventory\Application\InventoryManager;
use App\Modules\Inventory\Application\InventoryQueryService;
use App\Modules\Inventory\Contracts\InventoryReader;
use App\Modules\Inventory\Contracts\InventoryStore;
use App\Modules\Order\Application\OrderAdminService;
use App\Modules\Order\Application\OrderManager;
use App\Modules\Order\Application\OrderQueryService;
use App\Modules\Order\Contracts\OrderAdminPort;
use App\Modules\Order\Contracts\OrderCommandPort;
use App\Modules\Order\Contracts\OrderReader;
use App\Modules\Platform\Infrastructure\CartCheckoutAdapter;
use App\Modules\Platform\Infrastructure\CartCommandAdapter;
use App\Modules\Platform\Infrastructure\InventoryCheckoutAdapter;
use App\Modules\Platform\Infrastructure\InventoryStoreAdapter;
use App\Modules\Platform\Infrastructure\LegacyOrderReader;
use App\Modules\Platform\Infrastructure\OrderAdminAdapter;
use App\Modules\Platform\Infrastructure\OrderCheckoutAdapter;
use App\Modules\Platform\Infrastructure\OrderCommandAdapter;
use App\Modules\Platform\Infrastructure\PromotionCheckoutAdapter;
use App\Modules\Platform\Infrastructure\PromotionUsageReconciliationAdapter;
use App\Modules\Promotion\Contracts\PromotionUsageReconciler;
use Siro\Core\App;
use Siro\Core\Container;
final class CommerceCheckoutProvider
{
    public function register(?App $app = null): void
    {
        $c = Container::getInstance();
        $c->singleton(CartCommandAdapter::class); $c->singleton(CartCommandPort::class, fn (Container $c): object => $c->make(CartCommandAdapter::class)); $c->singleton(NativeCartService::class);
        $c->singleton(CartCheckoutAdapter::class); $c->singleton(CheckoutOrderPort::class, fn (Container $c): object => $c->make(OrderCheckoutAdapter::class)); $c->singleton(CartPort::class, fn (Container $c): object => $c->make(CartCheckoutAdapter::class));
        $c->singleton(OrderCheckoutAdapter::class); $c->singleton(InventoryCheckoutAdapter::class); $c->singleton(PromotionCheckoutAdapter::class); $c->singleton(PromotionUsageReconciliationAdapter::class); $c->singleton(PromotionUsageReconciler::class, fn (Container $c): object => $c->make(PromotionUsageReconciliationAdapter::class));
        $c->singleton(CheckoutInventoryPort::class, fn (Container $c): object => $c->make(InventoryCheckoutAdapter::class)); $c->singleton(PromotionPort::class, fn (Container $c): object => $c->make(PromotionCheckoutAdapter::class)); $c->singleton(OrderStatePort::class, fn (Container $c): object => $c->make(OrderManager::class));
        $c->singleton(PlaceOrderService::class); $c->singleton(CheckoutHandler::class, fn (Container $c): object => $c->make(PlaceOrderService::class)); $c->singleton(ModuleCheckoutService::class);
        $c->singleton(LegacyOrderReader::class); $c->singleton(OrderReader::class, fn (Container $c): object => $c->make(LegacyOrderReader::class)); $c->singleton(OrderQueryService::class);
        $c->singleton(OrderCommandAdapter::class); $c->singleton(OrderCommandPort::class, fn (Container $c): object => $c->make(OrderCommandAdapter::class)); $c->singleton(OrderManager::class);
        $c->singleton(OrderAdminAdapter::class); $c->singleton(OrderAdminPort::class, fn (Container $c): object => $c->make(OrderAdminAdapter::class)); $c->singleton(OrderAdminService::class); $c->singleton(\App\Modules\Promotion\Application\GiftCreditService::class);
        $c->singleton(InventoryStoreAdapter::class); $c->singleton(InventoryStore::class, fn (Container $c): object => $c->make(InventoryStoreAdapter::class)); $c->singleton(InventoryManager::class); $c->singleton(InventoryReader::class, fn (Container $c): object => $c->make(InventoryManager::class)); $c->singleton(InventoryQueryService::class);
    }
}
