<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $t): void {
            $t->string('provider', 64)->nullable();
        });
        Schema::create('product_subscription_plans', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('product_id', 26);
            $t->string('variant_id', 26)->nullable();
            $t->string('plan_id', 26);
            $t->integer('sort')->default(0);
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->index('product_id');
            $t->index('plan_id');
        });
    }

    public function down(): void
    {
        Schema::drop('product_subscription_plans');
        Schema::table('subscription_plans', function (Blueprint $t): void {
            $t->dropColumn('provider');
        });
    }
};
