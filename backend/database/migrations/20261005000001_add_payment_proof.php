<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t): void {
            $t->string('proof_reference', 128)->nullable();
            $t->string('proof_url', 512)->nullable();
            $t->datetime('proof_uploaded_at')->nullable();
            $t->index('proof_uploaded_at');
        });
        Schema::create('payment_proofs', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('payment_id', 26);
            $t->string('order_id', 26);
            $t->string('reference', 128);
            $t->string('file_url', 512)->nullable();
            $t->string('file_hash', 64)->nullable();
            $t->string('uploaded_by', 26)->nullable();
            $t->string('channel', 16)->default('storefront');
            $t->string('idempotency_key', 128)->nullable();
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->index('payment_id');
            $t->index('order_id');
            $t->unique('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::drop('payment_proofs');
        Schema::table('payments', function (Blueprint $t): void {
            $t->dropIndex('idx_payments_proof_uploaded_at');
            $t->dropColumn('proof_uploaded_at');
            $t->dropColumn('proof_url');
            $t->dropColumn('proof_reference');
        });
    }
};
