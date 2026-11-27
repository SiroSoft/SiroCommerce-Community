<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('ext_product_label_rules', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('store_id', 64);
            $t->string('channel_id', 64);
            $t->string('rule_key', 64);
            $t->string('text', 80);
            $t->string('kind', 32);
            $t->text('conditions_json')->nullable();
            $t->integer('priority')->default(100);
            $t->boolean('active')->default(true);
            $t->string('idempotency_key', 128)->nullable();
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->unique(['store_id', 'channel_id', 'rule_key']);
            $t->unique(['store_id', 'channel_id', 'idempotency_key']);
            $t->index(['store_id', 'channel_id', 'active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::drop('ext_product_label_rules');
    }
};
