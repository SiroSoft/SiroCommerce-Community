<?php
declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('finance_evidence_records', function (Blueprint $t): void {
            $t->string('id', 26); $t->string('aggregate_type', 32); $t->string('aggregate_id', 26);
            $t->string('kind', 64); $t->text('payload_json'); $t->string('payload_hash', 64);
            $t->datetime('retention_until')->nullable(); $t->integer('legal_hold')->default(0);
            $t->datetime('created_at')->nullable();
            $t->primary('id'); $t->unique(['aggregate_type', 'aggregate_id', 'kind', 'payload_hash'], 'uq_finance_evidence');
            $t->index(['retention_until', 'legal_hold']);
        });
        Schema::create('promotion_component_allocations', function (Blueprint $t): void {
            $t->string('id', 26); $t->string('order_id', 26); $t->string('line_id', 26)->nullable();
            $t->string('component_id', 26)->nullable(); $t->string('promotion_id', 26);
            $t->string('currency', 3); $t->integer('minor_unit'); $t->integer('discount_minor');
            $t->string('idempotency_key', 128); $t->text('evidence_json'); $t->datetime('created_at')->nullable();
            $t->primary('id'); $t->unique('idempotency_key'); $t->index(['order_id', 'promotion_id']);
        });
        Schema::create('consent_audit_events', function (Blueprint $t): void {
            $t->string('id', 26); $t->string('customer_id', 26); $t->string('purpose', 64);
            $t->integer('granted'); $t->string('version', 32); $t->string('source', 32);
            $t->datetime('occurred_at')->nullable(); $t->primary('id'); $t->index(['customer_id', 'purpose']);
        });
        Schema::create('privacy_requests', function (Blueprint $t): void {
            $t->string('id', 26); $t->string('customer_id', 26); $t->string('type', 24);
            $t->string('state', 24)->default('completed'); $t->string('idempotency_key', 128);
            $t->string('fingerprint', 64); $t->text('result_json')->nullable(); $t->integer('legal_hold')->default(0);
            $t->datetime('created_at')->nullable(); $t->datetime('completed_at')->nullable();
            $t->primary('id'); $t->unique('idempotency_key'); $t->index(['customer_id', 'type']);
        });
    }
    public function down(): void
    {
        Schema::drop('privacy_requests'); Schema::drop('consent_audit_events');
        Schema::drop('promotion_component_allocations'); Schema::drop('finance_evidence_records');
    }
};
