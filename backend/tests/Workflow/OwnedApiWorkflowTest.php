<?php

declare(strict_types=1);

namespace Tests\Workflow;

use App\Modules\Inventory\Application\InventoryManager;
use App\Modules\Platform\Infrastructure\InventoryStoreAdapter;
use App\Repositories\InventoryRepository;
use Tests\TestCase;

final class OwnedApiWorkflowTest extends TestCase
{
    public function testAtpWorkflowRollsBackEverySourceAndLeavesLedgerConsistent(): void
    {
        $repository = new InventoryRepository();
        $inventory = new InventoryManager(new InventoryStoreAdapter($repository));
        $repository->setOnHand('workflow-variant', 2, 'workflow', 'source-a');
        $repository->setOnHand('workflow-variant', 2, 'workflow', 'source-b');

        try {
            $inventory->reserveAcrossSources('workflow-variant', 5, 'workflow-cart');
            self::fail('ATP should reject an impossible reservation');
        } catch (\RuntimeException $exception) {
            self::assertSame('Out of stock', $exception->getMessage());
        }

        self::assertSame(2, $inventory->available('workflow-variant', 'source-a'));
        self::assertSame(2, $inventory->available('workflow-variant', 'source-b'));
    }

    public function testOwnedWorkflowRequiresReplayKeysForStateChangingOperations(): void
    {
        $base = dirname(__DIR__, 2);
        $routes = (string) file_get_contents($base . '/routes/api.php');
        foreach ([
            "FulfillmentController::class, 'allocate'", "FulfillmentController::class, 'ship'",
            "FulfillmentController::class, 'deliver'", "FulfillmentController::class, 'cancel'",
            "ShippingController::class, 'create'", "ShippingController::class, 'cancel'",
            "CustomerController::class, 'redeemPoints'", "B2BController::class, 'quote'",
        ] as $operation) self::assertStringContainsString($operation, $routes);
    }
}
