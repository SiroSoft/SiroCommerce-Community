<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('ai_review_queue', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('tenant_id', 64);
            $t->string('task', 64);
            $t->string('product_id', 64)->nullable();
            $t->string('state', 16)->default('pending');
            $t->text('input_json')->nullable();
            $t->text('output_json')->nullable();
            $t->string('provider', 32)->nullable();
            $t->string('model', 128)->nullable();
            $t->string('reason', 255)->nullable();
            $t->string('actor_id', 64)->nullable();
            $t->string('reviewed_by', 64)->nullable();
            $t->datetime('created_at')->nullable();
            $t->datetime('reviewed_at')->nullable();
            $t->primary('id');
            $t->index(['state', 'task']);
            $t->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::drop('ai_review_queue');
    }
};
