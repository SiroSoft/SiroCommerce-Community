<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('order_notification_deliveries', function (Blueprint $t): void {
            $t->string('provider', 64)->nullable();
            $t->datetime('next_attempt_at')->nullable();
            $t->datetime('dead_lettered_at')->nullable();
            $t->integer('replay_count')->default(0);
            $t->text('redacted_payload_json')->nullable();
            $t->index(['state', 'next_attempt_at'], 'idx_order_notification_delivery_retry');
        });
    }

    public function down(): void
    {
        Schema::table('order_notification_deliveries', function (Blueprint $t): void {
            $t->dropIndex('idx_order_notification_delivery_retry');
            $t->dropColumn('redacted_payload_json');
            $t->dropColumn('replay_count');
            $t->dropColumn('dead_lettered_at');
            $t->dropColumn('next_attempt_at');
            $t->dropColumn('provider');
        });
    }
};
