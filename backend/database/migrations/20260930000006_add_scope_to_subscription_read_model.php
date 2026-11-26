<?php

declare(strict_types=1);

use Siro\Core\Schema;
use Siro\Core\DB\Blueprint;

return new class {
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $t): void {
            $t->string('store_id', 64)->nullable();
            $t->string('channel_id', 64)->nullable();
            $t->index(['store_id', 'channel_id', 'state'], 'idx_subscriptions_admin_scope');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $t): void {
            $t->dropIndex('idx_subscriptions_admin_scope');
            $t->dropColumn('store_id');
            $t->dropColumn('channel_id');
        });
    }
};
