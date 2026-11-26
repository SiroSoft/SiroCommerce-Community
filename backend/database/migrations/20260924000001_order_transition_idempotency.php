<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('order_transitions', function (Blueprint $t): void {
            $t->string('idempotency_key', 128)->nullable();
            $t->unique(['order_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('order_transitions', function (Blueprint $t): void { $t->dropUnique('uq_order_transitions_order_id_idempotency_key'); $t->dropColumn('idempotency_key'); });
    }
};
