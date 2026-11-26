<?php

return [
    'product' => [
        'list' => 'Produktliste',
        'detail' => 'Produktdetails',
        'not_found' => 'Produkt nicht gefunden',
    ],
    'cart' => [
        'priced' => 'Warenkorb bepreist',
        'empty' => 'Warenkorb ist leer',
    ],
    'checkout' => [
        'placed' => 'Bestellung aufgegeben',
        'failed' => 'Checkout fehlgeschlagen: :reason',
        'idempotency_required' => 'Idempotency-Key-Header erforderlich',
        'duplicate' => 'Doppelte Anfrage — ursprüngliche Bestellung wird zurückgegeben',
    ],
    'order' => [
        'illegal_transition' => 'Unzulässiger Übergang :from -> :to',
        'cancelled' => 'Bestellung storniert',
        'refunded' => 'Bestellung erstattet',
    ],
    'payment' => [
        'already_processed' => 'Webhook bereits verarbeitet',
        'invalid_signature' => 'Ungültige Webhook-Signatur',
    ],
    'inventory' => [
        'out_of_stock' => 'Nicht auf Lager',
        'reserved' => 'Bestand reserviert',
    ],
    'v2' => [
        'payment_unknown' => 'Zahlungsergebnis muss abgeglichen werden',
        'webhook_accepted' => 'Webhook zur Verarbeitung angenommen',
        'webhook_duplicate' => 'Webhook wurde bereits empfangen',
        'queue_failed' => 'Hintergrundprozess fehlgeschlagen',
        'quote_expired' => 'Das Preisangebot ist abgelaufen',
        'shipment_created' => 'Versand wurde erstellt',
    ],
];
