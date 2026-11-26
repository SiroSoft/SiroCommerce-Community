<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('payment_operations', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('payment_id', 26);
            $t->string('type', 24);
            $t->integer('amount_minor');
            $t->string('currency', 3);
            $t->string('idempotency_key', 128);
            $t->string('state', 24)->default('received');
            $t->string('provider_ref', 255)->nullable();
            $t->string('request_hash', 64)->nullable();
            $t->text('result_json')->nullable();
            $t->text('last_error')->nullable();
            $t->datetime('created_at')->nullable();
            $t->datetime('completed_at')->nullable();
            $t->primary('id');
            $t->unique('idempotency_key');
            $t->index('payment_id');
        });
        Schema::create('payment_webhook_events', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('provider', 64);
            $t->string('event_id', 255);
            $t->string('payload_hash', 64);
            $t->integer('signature_ok')->default(0);
            $t->string('state', 24)->default('received');
            $t->text('payload_json')->nullable();
            $t->text('last_error')->nullable();
            $t->datetime('received_at')->nullable();
            $t->datetime('processed_at')->nullable();
            $t->primary('id');
            $t->unique(['provider', 'event_id']);
        });
        Schema::create('outbox_messages', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('event_id', 64);
            $t->string('topic', 128);
            $t->string('aggregate_type', 64);
            $t->string('aggregate_id', 64);
            $t->text('payload_json');
            $t->string('state', 24)->default('pending');
            $t->integer('attempts')->default(0);
            $t->datetime('available_at')->nullable();
            $t->datetime('locked_at')->nullable();
            $t->datetime('published_at')->nullable();
            $t->text('last_error')->nullable();
            $t->primary('id');
            $t->unique('event_id');
            $t->index(['state', 'available_at']);
        });
        // Siro Core owns the generic `jobs` table. Commerce uses a namespaced
        // table so the adapter can evolve without colliding with core queues.
        Schema::create('commerce_jobs', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('queue', 64);
            $t->string('name', 128);
            $t->string('idempotency_key', 128);
            $t->text('payload_json');
            $t->string('state', 24)->default('queued');
            $t->integer('attempts')->default(0);
            $t->integer('max_attempts')->default(5);
            $t->datetime('available_at')->nullable();
            $t->datetime('locked_at')->nullable();
            $t->string('worker_id', 128)->nullable();
            $t->text('last_error')->nullable();
            $t->primary('id');
            $t->unique('idempotency_key');
            $t->index(['queue', 'state', 'available_at']);
        });
        Schema::create('commerce_dead_letters', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('job_id', 26);
            $t->string('reason', 255);
            $t->text('payload_json');
            $t->datetime('failed_at')->nullable();
            $t->datetime('resolved_at')->nullable();
            $t->primary('id');
            $t->index('job_id');
        });
    }

    public function down(): void
    {
        Schema::drop('commerce_dead_letters');
        Schema::drop('commerce_jobs');
        Schema::drop('outbox_messages');
        Schema::drop('payment_webhook_events');
        Schema::drop('payment_operations');
    }
};
