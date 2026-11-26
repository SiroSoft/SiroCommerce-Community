<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->string('min_tier', 16)->nullable();
        });
        Schema::table('flash_sales', function (Blueprint $t): void {
            $t->string('min_tier', 16)->nullable();
        });
        Schema::table('users', function (Blueprint $t): void {
            $t->string('birthdate', 10)->nullable();
        });
        Schema::create('member_deals', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('name', 128);
            $t->string('kind', 16)->default('tier_window');
            $t->string('min_tier', 16)->nullable();
            $t->integer('percent_off');
            $t->datetime('starts_at')->nullable();
            $t->datetime('ends_at')->nullable();
            $t->string('state', 16)->default('active');
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->index(['state', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::drop('member_deals');
        Schema::table('users', function (Blueprint $t): void {
            $t->dropColumn('birthdate');
        });
        Schema::table('flash_sales', function (Blueprint $t): void {
            $t->dropColumn('min_tier');
        });
        Schema::table('products', function (Blueprint $t): void {
            $t->dropColumn('min_tier');
        });
    }
};
