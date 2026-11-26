<?php

return [
    'product' => [
        'list' => '商品列表',
        'detail' => '商品详情',
        'not_found' => '未找到商品',
    ],
    'cart' => [
        'priced' => '已计算购物车金额',
        'empty' => '购物车为空',
    ],
    'checkout' => [
        'placed' => '订单已提交',
        'failed' => '结账失败: :reason',
        'idempotency_required' => '缺少 Idempotency-Key 请求头',
        'duplicate' => '重复请求 — 返回原始订单',
    ],
    'order' => [
        'illegal_transition' => '非法状态流转 :from -> :to',
        'cancelled' => '订单已取消',
        'refunded' => '订单已退款',
    ],
    'payment' => [
        'already_processed' => 'Webhook 已处理',
        'invalid_signature' => 'Webhook 签名无效',
    ],
    'inventory' => [
        'out_of_stock' => '缺货',
        'reserved' => '已预留库存',
    ],
    'v2' => [
        'payment_unknown' => '支付结果需要对账',
        'webhook_accepted' => 'Webhook已接收并进入处理队列',
        'webhook_duplicate' => 'Webhook已接收过',
        'queue_failed' => '后台任务失败，需要检查',
        'quote_expired' => '报价已过期',
        'shipment_created' => '已创建发货单',
    ],
];
