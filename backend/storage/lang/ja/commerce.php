<?php

return [
    'product' => [
        'list' => '商品一覧',
        'detail' => '商品詳細',
        'not_found' => '商品が見つかりません',
    ],
    'cart' => [
        'priced' => 'カートの金額を計算しました',
        'empty' => 'カートは空です',
    ],
    'checkout' => [
        'placed' => '注文が確定しました',
        'failed' => 'チェックアウトに失敗しました: :reason',
        'idempotency_required' => 'Idempotency-Keyヘッダーが必要です',
        'duplicate' => '重複リクエスト — 元の注文を返します',
    ],
    'order' => [
        'illegal_transition' => '無効な状態遷移 :from -> :to',
        'cancelled' => '注文をキャンセルしました',
        'refunded' => '返金しました',
    ],
    'payment' => [
        'already_processed' => 'Webhookは処理済みです',
        'invalid_signature' => 'Webhookの署名が無効です',
    ],
    'inventory' => [
        'out_of_stock' => '在庫切れ',
        'reserved' => '在庫を確保しました',
    ],
    'v2' => [
        'payment_unknown' => '決済結果の照合が必要です',
        'webhook_accepted' => 'Webhookを処理キューに追加しました',
        'webhook_duplicate' => 'Webhookは既に受信済みです',
        'queue_failed' => 'バックグラウンド処理が失敗しました',
        'quote_expired' => '見積もりの有効期限が切れています',
        'shipment_created' => '配送を作成しました',
    ],
];
