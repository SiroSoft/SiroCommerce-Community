<?php
declare(strict_types=1);
use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;
return new class {
    public function up(): void
    {
        Schema::create('inventory_source_priorities', function (Blueprint $t) {
            $t->string('id', 26); $t->string('variant_id', 26); $t->string('source_id', 64); $t->integer('priority')->default(0); $t->string('state', 16)->default('active'); $t->primary('id'); $t->unique(['variant_id', 'source_id']); $t->index(['variant_id', 'priority']);
        });
        Schema::create('inventory_transfers', function (Blueprint $t) {
            $t->string('id', 26); $t->string('variant_id', 26); $t->string('from_source_id', 64); $t->string('to_source_id', 64); $t->integer('qty'); $t->string('actor', 64); $t->string('evidence_json')->default('{}'); $t->datetime('created_at')->nullable(); $t->primary('id'); $t->index(['variant_id', 'from_source_id']);
        });
        Schema::create('inventory_adjustments', function (Blueprint $t) {
            $t->string('id', 26); $t->string('variant_id', 26); $t->string('source_id', 64); $t->integer('old_on_hand'); $t->integer('new_on_hand'); $t->integer('delta'); $t->string('actor', 64); $t->string('evidence_json')->default('{}'); $t->datetime('created_at')->nullable(); $t->primary('id'); $t->index(['variant_id', 'source_id']);
        });
        Schema::create('company_catalogs', function (Blueprint $t) {
            $t->string('id', 26); $t->string('company_id', 26); $t->string('code', 64); $t->string('name', 128); $t->string('currency', 3); $t->string('state', 16)->default('active'); $t->primary('id'); $t->unique(['company_id', 'code']);
        });
        Schema::create('company_catalog_items', function (Blueprint $t) {
            $t->string('id', 26); $t->string('catalog_id', 26); $t->string('variant_id', 26); $t->integer('minimum_qty')->default(1); $t->primary('id'); $t->unique(['catalog_id', 'variant_id']);
        });
        Schema::create('company_negotiated_prices', function (Blueprint $t) {
            $t->string('id', 26); $t->string('company_id', 26); $t->string('variant_id', 26); $t->string('currency', 3); $t->integer('unit_price_minor'); $t->integer('minimum_qty')->default(1); $t->string('state', 16)->default('active'); $t->text('evidence_json'); $t->primary('id'); $t->unique(['company_id', 'variant_id', 'currency', 'minimum_qty'], 'uq_negotiated_price');
        });
        Schema::create('b2b_quotes', function (Blueprint $t) {
            $t->string('id', 26); $t->string('company_id', 26); $t->string('currency', 3); $t->string('state', 20)->default('draft'); $t->text('lines_json'); $t->text('totals_json'); $t->string('expires_at', 32)->nullable(); $t->string('idempotency_key', 255); $t->integer('created_by'); $t->primary('id'); $t->unique('idempotency_key'); $t->index(['company_id', 'state']);
        });
    }
    public function down(): void
    {
        Schema::drop('b2b_quotes'); Schema::drop('company_negotiated_prices'); Schema::drop('company_catalog_items'); Schema::drop('company_catalogs'); Schema::drop('inventory_adjustments'); Schema::drop('inventory_transfers'); Schema::drop('inventory_source_priorities');
    }
};
