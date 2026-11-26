<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('promotion_rules', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('name', 160);
            $t->string('kind', 32);
            $t->integer('value');
            $t->integer('min_subtotal_minor')->nullable();
            $t->text('category_slugs_json')->nullable();
            $t->datetime('starts_at')->nullable();
            $t->datetime('ends_at')->nullable();
            $t->integer('max_uses')->nullable();
            $t->integer('uses')->default(0);
            $t->string('state', 16)->default('active');
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->index(['state', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::drop('promotion_rules');
    }
};
