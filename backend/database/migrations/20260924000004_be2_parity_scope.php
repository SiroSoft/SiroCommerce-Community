<?php
declare(strict_types=1);
use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;
return new class {
    public function up(): void
    {
        Schema::table('commerce_jobs', function (Blueprint $t): void { $t->string('tenant_id', 64)->nullable(); $t->string('seller_id', 64)->nullable(); $t->string('site_id', 64)->nullable(); $t->string('store_id', 64)->nullable(); $t->string('channel_id', 64)->nullable(); $t->index(['tenant_id', 'seller_id', 'site_id', 'store_id', 'channel_id'], 'idx_commerce_jobs_scope'); });
        Schema::table('inventory_sources', function (Blueprint $t): void { $t->string('tenant_id', 64)->nullable(); $t->string('seller_id', 64)->nullable(); $t->string('site_id', 64)->nullable(); $t->index(['tenant_id', 'seller_id', 'site_id']); });
        Schema::table('inventory_transfers', function (Blueprint $t): void { $t->string('tenant_id', 64)->nullable(); $t->string('seller_id', 64)->nullable(); $t->string('site_id', 64)->nullable(); $t->string('store_id', 64)->nullable(); $t->index(['tenant_id', 'seller_id', 'site_id', 'store_id']); });
        Schema::table('reservations', function (Blueprint $t): void { $t->string('tenant_id', 64)->nullable(); $t->string('seller_id', 64)->nullable(); $t->string('site_id', 64)->nullable(); $t->index(['tenant_id', 'seller_id', 'site_id']); });
        Schema::create('commerce_vendors', function (Blueprint $t): void { $t->string('id', 26); $t->string('code', 64); $t->string('name', 128); $t->string('seller_id', 64)->nullable(); $t->string('tenant_id', 64)->nullable(); $t->string('store_id', 64); $t->string('state', 16)->default('active'); $t->datetime('created_at')->nullable(); $t->primary('id'); $t->unique(['store_id', 'code']); $t->index(['tenant_id', 'seller_id', 'store_id']); });
        Schema::create('commerce_audit_events', function (Blueprint $t): void { $t->string('id', 26); $t->string('action', 128); $t->string('actor', 128); $t->text('payload_json'); $t->string('tenant_id', 64)->nullable(); $t->string('seller_id', 64)->nullable(); $t->string('site_id', 64)->nullable(); $t->string('store_id', 64); $t->string('channel_id', 64); $t->datetime('created_at')->nullable(); $t->primary('id'); $t->index(['tenant_id', 'seller_id', 'site_id', 'store_id', 'channel_id'], 'idx_commerce_audit_scope'); });
        Schema::create('commerce_notification_events', function (Blueprint $t): void { $t->string('id', 26); $t->string('event_name', 128); $t->string('customer_id', 64)->nullable(); $t->string('order_id', 64)->nullable(); $t->string('state', 24)->default('queued'); $t->text('payload_json'); $t->string('tenant_id', 64)->nullable(); $t->string('seller_id', 64)->nullable(); $t->string('site_id', 64)->nullable(); $t->string('store_id', 64); $t->string('channel_id', 64); $t->datetime('created_at')->nullable(); $t->primary('id'); $t->index(['tenant_id', 'seller_id', 'site_id', 'store_id', 'channel_id'], 'idx_commerce_notification_scope'); });
    }
    public function down(): void { Schema::drop('commerce_vendors'); Schema::drop('commerce_notification_events'); Schema::drop('commerce_audit_events'); }
};
