<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

/**
 * Append-only audit trail for label-rule mutations (create/update/delete).
 * The extension route loader adds auth/cap/throttle/idempotency but no audit
 * middleware, and no reusable audit service exists for extensions, so the
 * plugin keeps its own scoped audit rows instead of writing core tables.
 */
return new class {
    public function up(): void
    {
        Schema::create('ext_product_label_audit', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('store_id', 64);
            $t->string('channel_id', 64);
            $t->string('actor', 160);
            $t->string('action', 32);
            $t->string('rule_id', 26)->nullable();
            $t->string('rule_key', 64)->nullable();
            $t->text('payload_json')->nullable();
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->index(['store_id', 'channel_id', 'created_at']);
            $t->index(['store_id', 'channel_id', 'rule_id']);
        });
    }

    public function down(): void
    {
        Schema::drop('ext_product_label_audit');
    }
};
