<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('ext_license_portal_deliveries', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('store_id', 64);
            $t->string('channel_id', 64);
            $t->string('customer_id', 64)->nullable();
            $t->string('order_id', 64)->nullable();
            $t->string('order_line_id', 26)->nullable();
            $t->string('sku', 64)->nullable();
            $t->string('source_sku', 64)->nullable();
            $t->string('license_id', 128)->nullable();
            $t->text('license_document')->nullable();
            $t->string('license_reference', 255)->nullable();
            $t->datetime('license_expires_at')->nullable();
            $t->string('token_hash', 128)->nullable();
            $t->datetime('token_expires_at')->nullable();
            $t->datetime('token_used_at')->nullable();
            $t->datetime('revoked_at')->nullable();
            $t->string('state', 16)->default('pending');
            $t->string('idempotency_key', 128)->nullable();
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->unique(['store_id', 'channel_id', 'idempotency_key'], 'uq_ext_lp_scope_idempotency');
            $t->unique(['store_id', 'channel_id', 'order_id', 'sku'], 'uq_ext_lp_scope_order_sku');
            $t->unique('token_hash', 'uq_ext_lp_token_hash');
            $t->index(['store_id', 'channel_id', 'state', 'created_at'], 'idx_ext_lp_scope_state');
            $t->index(['store_id', 'channel_id', 'customer_id'], 'idx_ext_lp_scope_customer');
            $t->index(['store_id', 'channel_id', 'order_id'], 'idx_ext_lp_scope_order');
        });
    }

    public function down(): void
    {
        Schema::drop('ext_license_portal_deliveries');
    }
};
