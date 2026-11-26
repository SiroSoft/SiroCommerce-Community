<?php
declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $t): void {
            $t->text('plan_snapshot_json')->nullable();
            $t->datetime('cancellation_effective_at')->nullable();
            $t->datetime('dunning_until')->nullable();
            $t->index(['state', 'dunning_until'], 'idx_subscriptions_dunning');
        });
        Schema::table('subscription_renewal_attempts', function (Blueprint $t): void {
            $t->string('order_id', 26)->nullable();
            $t->string('invoice_id', 26)->nullable();
            $t->index(['subscription_id', 'idempotency_key'], 'idx_subscription_attempt_identity');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_renewal_attempts', function (Blueprint $t): void {
            $t->dropIndex('idx_subscription_attempt_identity');
            $t->dropColumn('invoice_id');
            $t->dropColumn('order_id');
        });
        Schema::table('subscriptions', function (Blueprint $t): void {
            $t->dropIndex('idx_subscriptions_dunning');
            $t->dropColumn('dunning_until');
            $t->dropColumn('cancellation_effective_at');
            $t->dropColumn('plan_snapshot_json');
        });
    }
};
