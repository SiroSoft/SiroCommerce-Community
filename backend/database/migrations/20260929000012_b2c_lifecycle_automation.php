<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $t): void {
            $t->datetime('expires_at')->nullable();
            $t->index(['state', 'expires_at'], 'idx_carts_expiry');
        });
        Schema::create('marketing_automation_deliveries', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('campaign_key', 191);
            $t->string('signal', 32);
            $t->string('customer_id', 26);
            $t->string('cart_id', 26)->nullable();
            $t->string('product_id', 26)->nullable();
            $t->string('state', 24)->default('planned');
            $t->datetime('scheduled_at')->nullable();
            $t->datetime('sent_at')->nullable();
            $t->datetime('suppressed_at')->nullable();
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->unique('campaign_key');
            $t->index(['customer_id', 'signal', 'scheduled_at'], 'idx_marketing_frequency');
            $t->index(['cart_id', 'state'], 'idx_marketing_cart_state');
        });
    }

    public function down(): void
    {
        Schema::drop('marketing_automation_deliveries');
        Schema::table('carts', function (Blueprint $t): void {
            $t->dropIndex('idx_carts_expiry');
            $t->dropColumn('expires_at');
        });
    }
};
