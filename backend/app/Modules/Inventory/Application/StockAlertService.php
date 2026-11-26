<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Support\Ids;
use Siro\Core\Database;

/**
 * Product notifications (spec PRODUCT_NOTIFICATIONS_SPEC): back-in-stock,
 * price-drop and preorder subscriptions share the stock_alerts table.
 * Subscribe per product (optionally a variant); firing marks pending rows
 * notified and best-effort emails those with an address. Never throws the
 * request — firing paths swallow and count.
 */
final class StockAlertService
{
    public const TYPES = ['back_in_stock', 'price_drop', 'preorder'];

    /** @return array<string, mixed> */
    public function subscribe(string $productId, ?string $variantId, ?string $email, ?string $customerId, string $type = 'back_in_stock', ?int $targetPriceMinor = null): array
    {
        if ($productId === '' || strlen($productId) > 64) throw new \InvalidArgumentException('Product is invalid');
        if ($variantId !== null && ($variantId === '' || strlen($variantId) > 64)) throw new \InvalidArgumentException('Variant is invalid');
        if (!in_array($type, self::TYPES, true)) throw new \InvalidArgumentException('Type must be back_in_stock|price_drop|preorder');
        $email = $email !== null && trim($email) !== '' ? strtolower(trim($email)) : null;
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new \InvalidArgumentException('Email is invalid');
        if ($email === null && ($customerId === null || $customerId === '')) throw new \InvalidArgumentException('Email or sign-in is required');
        if ($type === 'price_drop') {
            if ($targetPriceMinor === null || $targetPriceMinor <= 0 || $targetPriceMinor > 2147483647) throw new \InvalidArgumentException('Target price must be between 1 and 2147483647');
        } else {
            $targetPriceMinor = null;
        }
        if ($type === 'preorder' && !$this->isPreorderProduct($productId)) throw new \InvalidArgumentException('Product is not available for preorder');
        try {
            $dup = Database::connection()->prepare("SELECT id FROM stock_alerts WHERE product_id = ? AND type = ? AND state = 'pending' AND ((variant_id IS NULL AND ? IS NULL) OR variant_id = ?) AND ((email IS NOT NULL AND email = ?) OR (customer_id IS NOT NULL AND customer_id = ?)) LIMIT 1");
            $dup->execute([$productId, $type, $variantId, $variantId, $email ?? '', $customerId ?? '']);
            $existing = $dup->fetchColumn();
            if (is_string($existing) && $existing !== '') throw new \InvalidArgumentException('Alert already exists');
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable) {
        }
        $row = [
            'id' => Ids::new(), 'product_id' => $productId, 'variant_id' => $variantId,
            'email' => $email, 'customer_id' => $customerId,
            'type' => $type, 'target_price_minor' => $targetPriceMinor,
            'price_at_subscribe_minor' => $variantId !== null ? $this->currentPrice($variantId) : null,
            'attempts' => 0, 'last_error' => null,
            'unsubscribe_token' => bin2hex(random_bytes(32)), 'state' => 'pending',
            'created_at' => date('Y-m-d H:i:s'), 'notified_at' => null, 'sent_at' => null,
        ];
        try {
            Database::table('stock_alerts')->insert($row);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Alert could not be saved');
        }
        return ['id' => $row['id'], 'product_id' => $productId, 'unsubscribe_token' => $row['unsubscribe_token'], 'state' => 'pending', 'type' => $type];
    }

    public function unsubscribe(string $token): bool
    {
        if ($token === '' || strlen($token) > 128) return false;
        try {
            $stmt = Database::connection()->prepare("UPDATE stock_alerts SET state = 'unsubscribed' WHERE unsubscribe_token = ? AND state = 'pending'");
            $stmt->execute([$token]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{notified: int, emailed: int} */
    public function checkRestock(string $variantId): array
    {
        $notified = 0;
        $emailed = 0;
        if (!$this->isAvailable($variantId)) return ['notified' => 0, 'emailed' => 0];
        try {
            $pdo = Database::connection();
            $productId = $this->productForVariant($variantId);
            $stmt = $pdo->prepare("SELECT id, product_id, variant_id, email, customer_id, type, unsubscribe_token FROM stock_alerts WHERE state = 'pending' AND type IN ('back_in_stock', 'preorder') AND (variant_id = ? OR (variant_id IS NULL AND product_id = ?))");
            $stmt->execute([$variantId, $productId ?? '']);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (!is_array($rows) || $rows === []) return ['notified' => 0, 'emailed' => 0];
            $productName = $this->productName((string) ($productId ?? ''));
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $id = (string) ($row['id'] ?? '');
                $mark = $pdo->prepare("UPDATE stock_alerts SET state = 'notified', notified_at = ? WHERE id = ? AND state = 'pending'");
                $mark->execute([date('Y-m-d H:i:s'), $id]);
                if ($mark->rowCount() === 0) continue;
                $notified++;
                if ($this->sendRow($row, $productName, null)) $emailed++;
            }
        } catch (\Throwable $e) {
            error_log('[stock-alerts] restock check failed: ' . $e->getMessage());
        }
        return ['notified' => $notified, 'emailed' => $emailed];
    }

    /** @return array{notified: int, emailed: int} */
    public function checkPriceDrop(string $variantId, int $newPriceMinor): array
    {
        $notified = 0;
        $emailed = 0;
        if ($newPriceMinor < 0) return ['notified' => 0, 'emailed' => 0];
        try {
            $pdo = Database::connection();
            $productId = $this->productForVariant($variantId);
            $stmt = $pdo->prepare("SELECT id, product_id, variant_id, email, customer_id, type, target_price_minor, unsubscribe_token FROM stock_alerts WHERE state = 'pending' AND type = 'price_drop' AND target_price_minor >= ? AND (variant_id = ? OR (variant_id IS NULL AND product_id = ?))");
            $stmt->execute([$newPriceMinor, $variantId, $productId ?? '']);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (!is_array($rows) || $rows === []) return ['notified' => 0, 'emailed' => 0];
            $productName = $this->productName((string) ($productId ?? ''));
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $mark = $pdo->prepare("UPDATE stock_alerts SET state = 'notified', notified_at = ? WHERE id = ? AND state = 'pending'");
                $mark->execute([date('Y-m-d H:i:s'), (string) ($row['id'] ?? '')]);
                if ($mark->rowCount() === 0) continue;
                $notified++;
                if ($this->sendRow($row, $productName, $newPriceMinor)) $emailed++;
            }
        } catch (\Throwable $e) {
            error_log('[stock-alerts] price-drop check failed: ' . $e->getMessage());
        }
        return ['notified' => $notified, 'emailed' => $emailed];
    }

    /** @return list<array<string, mixed>> */
    public function pending(?string $productId = null, ?string $type = null): array
    {
        try {
            $pdo = Database::connection();
            $sql = 'SELECT id, product_id, variant_id, email, customer_id, type, target_price_minor, price_at_subscribe_minor, state, created_at, notified_at, sent_at FROM stock_alerts WHERE 1 = 1';
            $params = [];
            if ($productId !== null && $productId !== '') {
                $sql .= ' AND product_id = ?';
                $params[] = $productId;
            }
            if ($type !== null && $type !== '') {
                $sql .= ' AND type = ?';
                $params[] = $type;
            }
            $stmt = $pdo->prepare($sql . ' ORDER BY created_at DESC LIMIT 200');
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Send one fired row; records attempts/sent_at/last_error. Never throws. */
    private function sendRow(array $row, string $productName, ?int $newPriceMinor): bool
    {
        $id = (string) ($row['id'] ?? '');
        $email = isset($row['email']) && is_string($row['email']) ? $row['email'] : '';
        $customerId = isset($row['customer_id']) && is_string($row['customer_id']) ? $row['customer_id'] : '';
        $type = isset($row['type']) && is_string($row['type']) ? $row['type'] : 'back_in_stock';
        $token = isset($row['unsubscribe_token']) && is_string($row['unsubscribe_token']) ? $row['unsubscribe_token'] : '';
        if ($email === '') return false;
        if ($customerId !== '' && !$this->transactionalOptedIn($customerId)) return false;
        try {
            $pdo = Database::connection();
            $pdo->prepare('UPDATE stock_alerts SET attempts = attempts + 1 WHERE id = ?')->execute([$id]);
            [$subject, $text] = match ($type) {
                'price_drop' => [
                    'Price drop at SiroCommerce' . ($productName !== '' ? ': ' . $productName : ''),
                    'Good news — ' . ($productName !== '' ? $productName : 'an item you asked about') . ' is now ' . $this->money($newPriceMinor ?? 0) . ", at or below your target.\n\nShop now while availability lasts.\n\nUnsubscribe: /api/products/stock-alerts/unsubscribe?token=" . $token,
                ],
                'preorder' => [
                    'Available for preorder at SiroCommerce' . ($productName !== '' ? ': ' . $productName : ''),
                    'Good news — ' . ($productName !== '' ? $productName : 'an item you asked about') . " is now available for preorder.\n\nReserve yours today.\n\nUnsubscribe: /api/products/stock-alerts/unsubscribe?token=" . $token,
                ],
                default => [
                    'Back in stock at SiroCommerce' . ($productName !== '' ? ': ' . $productName : ''),
                    'Good news — ' . ($productName !== '' ? $productName : 'an item you asked about') . " is back in stock.\n\nShop now while availability lasts.\n\nUnsubscribe: /api/products/stock-alerts/unsubscribe?token=" . $token,
                ],
            };
            $sent = \App\Support\Mailer::send($email, $subject, $text);
            $pdo->prepare($sent ? 'UPDATE stock_alerts SET sent_at = ? WHERE id = ?' : 'UPDATE stock_alerts SET last_error = ? WHERE id = ?')->execute($sent ? [date('Y-m-d H:i:s'), $id] : ['send returned false', $id]);
            return $sent;
        } catch (\Throwable $e) {
            try {
                Database::connection()->prepare('UPDATE stock_alerts SET last_error = ? WHERE id = ?')->execute([substr($e->getMessage(), 0, 200), $id]);
            } catch (\Throwable) {
            }
            return false;
        }
    }

    private function transactionalOptedIn(string $customerId): bool
    {
        try {
            $stmt = Database::connection()->prepare('SELECT transactional_email FROM customer_notification_preferences WHERE customer_id = ? LIMIT 1');
            $stmt->execute([$customerId]);
            $value = $stmt->fetchColumn();
            if ($value === false || $value === null) return true;
            return ((int) $value) === 1;
        } catch (\Throwable) {
            return true;
        }
    }

    private function isPreorderProduct(string $productId): bool
    {
        try {
            $stmt = Database::connection()->prepare('SELECT is_preorder FROM products WHERE id = ? LIMIT 1');
            $stmt->execute([$productId]);
            return ((int) $stmt->fetchColumn()) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isAvailable(string $variantId): bool
    {
        try {
            $stmt = Database::connection()->prepare('SELECT COALESCE(on_hand, 0) - COALESCE(reserved, 0) FROM inventory_items WHERE variant_id = ? LIMIT 1');
            $stmt->execute([$variantId]);
            return ((int) $stmt->fetchColumn()) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function currentPrice(string $variantId): ?int
    {
        try {
            $stmt = Database::connection()->prepare('SELECT price_minor FROM product_variants WHERE id = ? LIMIT 1');
            $stmt->execute([$variantId]);
            $value = $stmt->fetchColumn();
            return is_numeric($value) ? (int) $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function productForVariant(string $variantId): ?string
    {
        try {
            $stmt = Database::connection()->prepare('SELECT product_id FROM product_variants WHERE id = ? LIMIT 1');
            $stmt->execute([$variantId]);
            $productId = $stmt->fetchColumn();
            return is_string($productId) && $productId !== '' ? $productId : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function productName(string $productId): string
    {
        if ($productId === '') return '';
        try {
            $stmt = Database::connection()->prepare('SELECT name FROM products WHERE id = ? LIMIT 1');
            $stmt->execute([$productId]);
            $name = $stmt->fetchColumn();
            return is_string($name) ? $name : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function money(int $minor): string
    {
        return number_format($minor / 100, 2);
    }
}
