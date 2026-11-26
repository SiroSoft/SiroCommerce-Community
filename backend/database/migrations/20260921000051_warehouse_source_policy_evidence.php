<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('inventory_sources', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('source_id', 64);
            $t->string('warehouse_id', 64)->nullable();
            $t->string('store_id', 26)->nullable();
            $t->string('company_id', 26)->nullable();
            $t->integer('priority')->default(0);
            $t->integer('cost_minor')->default(0);
            $t->integer('distance_km')->default(0);
            $t->integer('lead_time_hours')->default(0);
            $t->string('state', 16)->default('active');
            $t->text('evidence_json')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->unique('source_id');
            $t->index(['company_id', 'store_id', 'state']);
        });

        Schema::table('reservations', function (Blueprint $t): void {
            $t->string('idempotency_key', 128)->nullable();
            $t->string('store_id', 26)->nullable();
            $t->string('company_id', 26)->nullable();
            $t->string('policy', 24)->default('fail');
            $t->text('evidence_json')->nullable();
            $t->datetime('released_at')->nullable();
            $t->unique('idempotency_key');
            $t->index(['store_id', 'company_id', 'state']);
        });

        Schema::table('inventory_transfers', function (Blueprint $t): void {
            $t->string('state', 20)->default('completed');
            $t->string('idempotency_key', 128)->nullable();
            $t->datetime('dispatched_at')->nullable();
            $t->datetime('received_at')->nullable();
            $t->unique('idempotency_key');
        });

        Schema::table('fulfillment_orders', function (Blueprint $t): void {
            $t->string('store_id', 26)->nullable();
            $t->string('company_id', 26)->nullable();
            $t->text('allocation_evidence_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_orders', function (Blueprint $t): void {
            $t->dropColumn('allocation_evidence_json'); $t->dropColumn('company_id'); $t->dropColumn('store_id');
        });
        Schema::table('inventory_transfers', function (Blueprint $t): void {
            $t->dropUnique('uq_inventory_transfers_idempotency_key'); $t->dropColumn('received_at'); $t->dropColumn('dispatched_at'); $t->dropColumn('idempotency_key'); $t->dropColumn('state');
        });
        Schema::table('reservations', function (Blueprint $t): void {
            $t->dropIndex('idx_reservations_store_id_company_id_state'); $t->dropUnique('uq_reservations_idempotency_key');
            $t->dropColumn('released_at'); $t->dropColumn('evidence_json'); $t->dropColumn('policy'); $t->dropColumn('company_id'); $t->dropColumn('store_id'); $t->dropColumn('idempotency_key');
        });
        Schema::drop('inventory_sources');
    }
};
