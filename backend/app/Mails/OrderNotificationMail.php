<?php

declare(strict_types=1);

namespace App\Mails;

/** Small, escaped templates for transactional order notifications. */
final class OrderNotificationMail
{
    /** @param array<string, mixed> $data */
    public function subject(string $template, array $data): string
    {
        $number = htmlspecialchars(is_scalar($data['number'] ?? null) ? (string) $data['number'] : 'order', ENT_QUOTES, 'UTF-8');
        return match ($template) {
            'order_placed' => 'Order ' . $number . ' received',
            'payment_captured' => 'Payment received for order ' . $number,
            'payment_failed' => 'Payment could not be completed for order ' . $number,
            'shipment_shipped' => 'Order ' . $number . ' has shipped',
            'shipment_delivered' => 'Order ' . $number . ' was delivered',
            'pickup_ready' => 'Order ' . $number . ' is ready for pickup',
            'pickup_collected' => 'Order ' . $number . ' was collected',
            'refund_completed', 'refund_issued', 'credit_memo_issued' => 'Refund issued for order ' . $number,
            'return_resolved', 'rma_completed' => 'Return completed for order ' . $number,
            'back_in_stock' => 'Back in stock at SiroCommerce',
            'price_drop' => 'Price drop at SiroCommerce',
            'preorder_ready' => 'Available for preorder at SiroCommerce',
            default => 'Order update for ' . $number,
        };
    }

    /** @param array<string, mixed> $data */
    public function build(string $template, array $data): string
    {
        $number = htmlspecialchars(is_scalar($data['number'] ?? null) ? (string) $data['number'] : '', ENT_QUOTES, 'UTF-8');
        $message = match ($template) {
            'order_placed' => 'We received your order.',
            'payment_captured' => 'Your payment was captured successfully.',
            'payment_failed' => 'Your payment could not be completed. Please try again or choose another payment method.',
            'shipment_shipped' => 'Your order is on its way.',
            'shipment_delivered' => 'Your order was marked as delivered.',
            'pickup_ready' => 'Your order is staged at the counter and ready for pickup.',
            'pickup_collected' => 'Your pickup was completed. Thank you for shopping with us.',
            'refund_completed', 'refund_issued', 'credit_memo_issued' => 'A refund has been completed.',
            'return_resolved', 'rma_completed' => 'Your return has been resolved.',
            'back_in_stock' => 'An item you asked about is back in stock.',
            'price_drop' => 'An item you asked about has dropped in price.',
            'preorder_ready' => 'An item you asked about is now available for preorder.',
            default => 'There is an update to your order.',
        };
        return '<!doctype html><html><body><p>Order <strong>' . $number . '</strong></p><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    }
}
