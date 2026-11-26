<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $t) {
            $t->integer('length_mm')->default(0);
            $t->integer('width_mm')->default(0);
            $t->integer('height_mm')->default(0);
            $t->string('currency', 3)->default('VND');
            $t->string('status', 32)->default('active');
            $t->index(['product_id', 'status']);
        });
        Schema::create('variant_option_values', function (Blueprint $t) {
            $t->string('variant_id', 26);
            $t->string('option_id', 26);
            $t->string('option_value_id', 26);
            $t->primary(['variant_id', 'option_id']);
            $t->unique(['variant_id', 'option_value_id']);
            $t->index('option_value_id');
        });
        Schema::create('shipping_provider_evidence', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('provider', 64);
            $t->string('operation', 64);
            $t->string('idempotency_key', 128);
            $t->string('provider_reference', 128)->nullable();
            $t->string('status', 32);
            $t->text('request_json');
            $t->text('response_json');
            $t->timestamps();
            $t->primary('id');
            $t->unique(['provider', 'operation', 'idempotency_key']);
            $t->index(['provider', 'provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::drop('shipping_provider_evidence');
        Schema::drop('variant_option_values');
        Schema::dropColumn('product_variants', 'status');
        Schema::dropColumn('product_variants', 'currency');
        Schema::dropColumn('product_variants', 'height_mm');
        Schema::dropColumn('product_variants', 'width_mm');
        Schema::dropColumn('product_variants', 'length_mm');
    }
};
