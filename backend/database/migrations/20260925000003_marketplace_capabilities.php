<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('commerce_vendors', function (Blueprint $t): void {
            $t->string('status', 24)->default('pending');
            $t->text('profile_json')->nullable();
            $t->integer('version')->default(1);
            $t->datetime('updated_at')->nullable();
            $t->index(['store_id', 'status']);
        });
        Schema::create('commerce_vendor_catalog', function (Blueprint $t): void {
            $t->string('id', 26); $t->string('vendor_id', 26); $t->string('product_id', 26);
            $t->string('tenant_id', 64)->nullable(); $t->string('seller_id', 64)->nullable();
            $t->string('store_id', 64); $t->string('channel_id', 64); $t->string('visibility', 24)->default('draft');
            $t->integer('version')->default(1); $t->datetime('created_at')->nullable(); $t->datetime('updated_at')->nullable();
            $t->primary('id'); $t->unique(['vendor_id', 'product_id', 'store_id', 'channel_id'], 'uq_vendor_catalog_scope');
            $t->index(['tenant_id', 'seller_id', 'store_id', 'channel_id', 'visibility'], 'idx_vendor_catalog_scope');
        });
        Schema::create('commerce_commission_ledger', function (Blueprint $t): void {
            $t->string('id', 26); $t->string('vendor_id', 26); $t->string('order_id', 32)->nullable(); $t->string('seller_order_id', 32)->nullable();
            $t->string('request_key', 192); $t->string('fingerprint', 64); $t->integer('gross_minor'); $t->integer('commission_minor'); $t->integer('net_minor'); $t->integer('rate_bps');
            $t->string('currency', 3); $t->string('state', 24)->default('posted'); $t->string('tenant_id', 64)->nullable(); $t->string('seller_id', 64)->nullable(); $t->string('store_id', 64); $t->string('channel_id', 64); $t->datetime('created_at')->nullable();
            $t->primary('id'); $t->unique(['vendor_id', 'request_key']); $t->index(['tenant_id', 'seller_id', 'store_id', 'channel_id', 'vendor_id'], 'idx_commission_scope');
        });
        Schema::create('commerce_seller_orders', function (Blueprint $t): void {
            $t->string('id', 32); $t->string('order_id', 32); $t->string('vendor_id', 26); $t->string('state', 24)->default('pending');
            $t->integer('subtotal_minor'); $t->integer('commission_minor')->default(0); $t->integer('net_minor')->default(0); $t->string('currency', 3); $t->text('line_ids_json');
            $t->string('tenant_id', 64)->nullable(); $t->string('seller_id', 64)->nullable(); $t->string('store_id', 64); $t->string('channel_id', 64); $t->datetime('created_at')->nullable(); $t->datetime('updated_at')->nullable();
            $t->primary('id'); $t->unique(['order_id', 'vendor_id']); $t->index(['tenant_id', 'seller_id', 'store_id', 'channel_id', 'vendor_id'], 'idx_seller_order_scope');
        });
        Schema::create('commerce_payouts', function (Blueprint $t): void {
            $t->string('id', 26); $t->string('vendor_id', 26); $t->integer('amount_minor'); $t->string('currency', 3); $t->string('state', 24)->default('pending'); $t->string('provider_status', 32)->default('BLOCKED_EXTERNAL');
            $t->string('metadata_json'); $t->string('tenant_id', 64)->nullable(); $t->string('seller_id', 64)->nullable(); $t->string('store_id', 64); $t->string('channel_id', 64); $t->string('idempotency_key', 192); $t->datetime('created_at')->nullable(); $t->datetime('updated_at')->nullable();
            $t->primary('id'); $t->unique('idempotency_key'); $t->index(['tenant_id', 'seller_id', 'store_id', 'channel_id', 'vendor_id', 'state'], 'idx_payout_scope');
        });
    }

    public function down(): void
    {
        Schema::drop('commerce_payouts'); Schema::drop('commerce_seller_orders'); Schema::drop('commerce_commission_ledger'); Schema::drop('commerce_vendor_catalog');
    }
};
