<?php
declare(strict_types=1);
namespace App\Modules\Inventory\Application;
use App\Modules\Inventory\Contracts\InventoryAdminPort;
final class InventoryAdminService
{
    public function __construct(private readonly InventoryAdminPort $inventory) {}
    /** @return array<string,mixed> */
    public function setOnHand(string $variantId, int $onHand, string $actor, string $sourceId = 'default', string $idempotencyKey = ''): array
    {
        $before = $this->available($variantId, $sourceId);
        $result = $this->inventory->setOnHand($variantId, $onHand, $actor, $sourceId, $idempotencyKey);
        // 0 → available restock wakes pending back-in-stock alerts.
        if ($before <= 0 && $this->available($variantId, $sourceId) > 0) {
            $result['stock_alerts'] = (new StockAlertService())->checkRestock($variantId);
        }
        return $result;
    }

    private function available(string $variantId, string $sourceId): int
    {
        try {
            $stmt = \Siro\Core\Database::connection()->prepare('SELECT COALESCE(on_hand, 0) - COALESCE(reserved, 0) FROM inventory_items WHERE variant_id = ? AND source_id = ? LIMIT 1');
            $stmt->execute([$variantId, $sourceId]);
            return max(0, (int) $stmt->fetchColumn());
        } catch (\Throwable) {
            return 0;
        }
    }
    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function transfer(array $data): array { return $this->inventory->transfer($data); }
    /** @return array<string,mixed> */ public function setSourcePriority(string $variantId, string $sourceId, int $priority): array { return $this->inventory->setSourcePriority($variantId, $sourceId, $priority); }
}
