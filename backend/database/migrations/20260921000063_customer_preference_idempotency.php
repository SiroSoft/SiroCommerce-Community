<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('customer_notification_preferences', function (Blueprint $t): void {
            $t->string('idempotency_key', 128)->nullable();
            $t->string('request_fingerprint', 64)->nullable();
            $t->unique('idempotency_key', 'uq_customer_preferences_idempotency');
        });
    }

    public function down(): void
    {
        Schema::table('customer_notification_preferences', function (Blueprint $t): void {
            $t->dropUnique('uq_customer_preferences_idempotency');
            $t->dropColumn('request_fingerprint');
            $t->dropColumn('idempotency_key');
        });
    }
};
