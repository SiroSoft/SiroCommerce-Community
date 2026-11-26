<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->string('publish_state', 24)->default('draft');
            $t->datetime('publish_at')->nullable();
            $t->datetime('unpublish_at')->nullable();
            $t->text('delivery_metadata_json')->nullable();
            $t->text('eligibility_rules_json')->nullable();
        });
        Schema::table('subscription_plans', function (Blueprint $t): void {
            $t->text('metadata_json')->nullable();
            $t->string('provider_authorization_state', 24)->default('external_required');
            $t->string('trial_period', 16)->nullable();
            $t->integer('trial_period_count')->nullable();
        });
        Schema::create('product_voucher_codes', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('product_id', 26);
            $t->string('code_hash', 64);
            $t->string('code_mask', 32);
            $t->string('state', 24)->default('available');
            $t->datetime('expires_at')->nullable();
            $t->string('redeemed_by', 26)->nullable();
            $t->string('redeemed_order_id', 26)->nullable();
            $t->datetime('redeemed_at')->nullable();
            $t->datetime('created_at')->nullable();
            $t->string('idempotency_key', 128)->nullable();
            $t->primary('id');
            $t->unique(['product_id', 'code_hash']);
            $t->unique('idempotency_key');
            $t->index(['product_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::drop('product_voucher_codes');
        Schema::table('subscription_plans', function (Blueprint $t): void {
            $t->dropColumn('trial_period_count');
            $t->dropColumn('trial_period');
            $t->dropColumn('provider_authorization_state');
            $t->dropColumn('metadata_json');
        });
        Schema::table('products', function (Blueprint $t): void {
            $t->dropColumn('eligibility_rules_json');
            $t->dropColumn('delivery_metadata_json');
            $t->dropColumn('unpublish_at');
            $t->dropColumn('publish_at');
            $t->dropColumn('publish_state');
        });
    }
};
