<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('pickup_points', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('code', 32);
            $t->string('name', 128);
            $t->string('store_id', 26)->nullable();
            $t->string('source_id', 26)->default('default');
            $t->string('address_line', 255)->nullable();
            $t->string('city', 128)->nullable();
            $t->integer('sla_hours')->default(24);
            $t->string('state', 16)->default('active');
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->unique('code');
            $t->index(['state', 'code']);
        });
        Schema::table('orders', function (Blueprint $t): void {
            $t->string('pickup_point_code', 32)->nullable();
        });
        Schema::table('fulfillment_orders', function (Blueprint $t): void {
            $t->string('pickup_point_code', 32)->nullable();
            $t->datetime('ready_at')->nullable();
            $t->datetime('ready_by')->nullable();
            $t->datetime('picked_up_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_orders', function (Blueprint $t): void {
            $t->dropColumn('picked_up_at');
            $t->dropColumn('ready_by');
            $t->dropColumn('ready_at');
            $t->dropColumn('pickup_point_code');
        });
        Schema::table('orders', function (Blueprint $t): void {
            $t->dropColumn('pickup_point_code');
        });
        Schema::drop('pickup_points');
    }
};
