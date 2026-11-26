<?php

return [
    'product' => [
        'list' => 'Products list',
        'detail' => 'Product detail',
        'not_found' => 'Product not found',
    ],
    'cart' => [
        'priced' => 'Cart priced',
        'empty' => 'Cart is empty',
    ],
    'checkout' => [
        'placed' => 'Order placed',
        'failed' => 'Checkout failed: :reason',
        'idempotency_required' => 'Idempotency-Key header is required',
        'duplicate' => 'Duplicate request — returning original order',
    ],
    'order' => [
        'illegal_transition' => 'Illegal transition :from -> :to',
        'cancelled' => 'Order cancelled',
        'refunded' => 'Order refunded',
    ],
    'payment' => [
        'already_processed' => 'Webhook already processed',
        'invalid_signature' => 'Invalid webhook signature',
    ],
    'inventory' => [
        'out_of_stock' => 'Out of stock',
        'reserved' => 'Stock reserved',
    ],
    'v2' => [
        'payment_unknown' => 'Payment result requires reconciliation',
        'webhook_accepted' => 'Webhook accepted for processing',
        'webhook_duplicate' => 'Webhook already received',
        'queue_failed' => 'Background operation failed and needs review',
        'quote_expired' => 'The pricing quote has expired',
        'shipment_created' => 'Shipment created',
    ],
];
