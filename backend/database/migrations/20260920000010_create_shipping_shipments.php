<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('shipping_shipments', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('provider', 64);
            $t->string('provider_reference', 128)->nullable();
            $t->string('tracking_number', 128)->nullable();
            $t->string('order_id', 26)->nullable();
            $t->string('fulfillment_id', 26)->nullable();
            $t->string('state', 32);
            $t->string('idempotency_key', 128);
            $t->text('payload_json');
            $t->timestamps();
            $t->primary('id');
            $t->unique(['provider', 'idempotency_key']);
            $t->index(['provider', 'tracking_number']);
            $t->index(['order_id', 'fulfillment_id']);
        });
        Schema::create('shipping_tracking_events', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('shipment_id', 26);
            $t->string('event_id', 128);
            $t->string('provider_status', 64);
            $t->string('state', 32);
            $t->text('payload_json');
            $t->timestamps();
            $t->primary('id');
            $t->unique(['shipment_id', 'event_id']);
            $t->index('shipment_id');
        });
    }

    public function down(): void
    {
        Schema::drop('shipping_tracking_events');
        Schema::drop('shipping_shipments');
    }
};
