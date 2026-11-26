<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('ext_product_qa', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('store_id', 64);
            $t->string('channel_id', 64);
            $t->string('product_id', 64);
            $t->string('product_slug', 160)->nullable();
            $t->string('customer_name', 80);
            $t->string('question', 1000);
            $t->text('answer')->nullable();
            $t->string('state', 16)->default('pending');
            $t->string('idempotency_key', 128)->nullable();
            $t->datetime('created_at')->nullable();
            $t->datetime('answered_at')->nullable();
            $t->primary('id');
            $t->unique(['store_id', 'channel_id', 'idempotency_key']);
            $t->index(['store_id', 'channel_id', 'product_id', 'state', 'created_at'], 'idx_ext_qa_scope_product');
            $t->index(['store_id', 'channel_id', 'state', 'created_at'], 'idx_ext_qa_scope_state');
        });
    }

    public function down(): void
    {
        Schema::drop('ext_product_qa');
    }
};
