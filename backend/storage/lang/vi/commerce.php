<?php

return [
    'product' => [
        'list' => 'Danh sách sản phẩm',
        'detail' => 'Chi tiết sản phẩm',
        'not_found' => 'Không tìm thấy sản phẩm',
    ],
    'cart' => [
        'priced' => 'Đã tính giá giỏ hàng',
        'empty' => 'Giỏ hàng trống',
    ],
    'checkout' => [
        'placed' => 'Đã đặt hàng',
        'failed' => 'Thanh toán thất bại: :reason',
        'idempotency_required' => 'Thiếu header Idempotency-Key',
        'duplicate' => 'Yêu cầu trùng — trả về đơn hàng gốc',
    ],
    'order' => [
        'illegal_transition' => 'Chuyển trạng thái không hợp lệ :from -> :to',
        'cancelled' => 'Đã hủy đơn hàng',
        'refunded' => 'Đã hoàn tiền',
    ],
    'payment' => [
        'already_processed' => 'Webhook đã xử lý trước đó',
        'invalid_signature' => 'Chữ ký webhook không hợp lệ',
    ],
    'inventory' => [
        'out_of_stock' => 'Hết hàng',
        'reserved' => 'Đã giữ hàng',
    ],
    'v2' => [
        'payment_unknown' => 'Kết quả thanh toán cần đối soát',
        'webhook_accepted' => 'Webhook đã được tiếp nhận để xử lý',
        'webhook_duplicate' => 'Webhook đã được tiếp nhận trước đó',
        'queue_failed' => 'Tác vụ nền thất bại và cần được kiểm tra',
        'quote_expired' => 'Báo giá đã hết hạn',
        'shipment_created' => 'Đã tạo vận đơn',
    ],
];
