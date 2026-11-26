<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('name', 255);
            $t->string('slug', 80);
            $t->string('status', 24)->default('active');
            $t->integer('commission_bps')->default(0);
            $t->string('contact_email', 255)->nullable();
            $t->text('description')->nullable();
            $t->timestamps();
            $t->primary('id');
            $t->unique('slug');
        });
        Schema::table('products', function (Blueprint $t): void {
            $t->string('vendor_id', 26)->nullable();
            $t->index('vendor_id', 'idx_products_vendor_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->dropIndex('idx_products_vendor_id');
            $t->dropColumn('vendor_id');
        });
        Schema::drop('vendors');
    }
};
