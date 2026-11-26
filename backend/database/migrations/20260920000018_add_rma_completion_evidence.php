<?php
declare(strict_types=1);
use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;
return new class {
    public function up(): void
    {
        Schema::table('return_requests', function (Blueprint $t) { $t->string('label_idempotency_key', 128)->nullable(); $t->string('label_provider', 64)->nullable(); $t->string('label_provider_ref', 255)->nullable(); $t->string('label_tracking', 128)->nullable(); $t->string('label_url')->nullable(); $t->string('label_state', 32)->nullable(); $t->integer('label_attempts')->default(0); $t->text('label_last_error')->nullable(); $t->datetime('label_locked_at')->nullable(); $t->unique('label_idempotency_key'); });
        Schema::create('return_inventory_postings', function (Blueprint $t) { $t->string('id', 26); $t->string('return_id', 26); $t->string('return_line_id', 26); $t->string('variant_id', 26); $t->integer('qty'); $t->string('disposition', 32); $t->string('source_id', 64)->default('default'); $t->datetime('created_at')->nullable(); $t->primary('id'); $t->unique(['return_id', 'return_line_id']); });
        Schema::create('return_events', function (Blueprint $t) { $t->string('id', 26); $t->string('return_id', 26); $t->string('event_key', 128); $t->string('state', 32); $t->text('payload_json'); $t->datetime('created_at')->nullable(); $t->primary('id'); $t->unique(['return_id', 'event_key']); $t->index('return_id'); });
    }
    public function down(): void
    {
        Schema::drop('return_events');
        Schema::drop('return_inventory_postings');
        Schema::table('return_requests', function (Blueprint $t) {
            $t->dropUnique('uq_return_requests_label_idempotency_key');
            $t->dropColumn('label_state');
            $t->dropColumn('label_url');
            $t->dropColumn('label_tracking');
            $t->dropColumn('label_provider_ref');
            $t->dropColumn('label_provider');
            $t->dropColumn('label_idempotency_key');
            $t->dropColumn('label_locked_at');
            $t->dropColumn('label_last_error');
            $t->dropColumn('label_attempts');
        });
    }
};
