<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('ai_budgets', function (Blueprint $t) {
            $t->string('tenant_id', 64);
            $t->integer('limit_minor')->default(0);
            $t->integer('spent_minor')->default(0);
            $t->datetime('updated_at')->nullable();
            $t->primary('tenant_id');
        });
        Schema::create('ai_audits', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('tenant_id', 64);
            $t->string('actor_id', 64)->nullable();
            $t->string('task', 64);
            $t->string('status', 32);
            $t->string('provider', 32)->nullable();
            $t->string('model', 128)->nullable();
            $t->string('usage_json', 2048)->nullable();
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->index(['tenant_id', 'task']);
        });
    }

    public function down(): void
    {
        Schema::drop('ai_audits');
        Schema::drop('ai_budgets');
    }
};
