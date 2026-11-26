<?php
declare(strict_types=1);
use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;
return new class {
    public function up(): void
    {
        Schema::create('flash_sales', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('name', 120);
            $t->string('state', 16)->default('draft');
            $t->datetime('starts_at');
            $t->datetime('ends_at');
            $t->integer('priority')->default(0);
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->index('state');
            $t->index('starts_at');
            $t->index('ends_at');
        });
        Schema::create('flash_sale_items', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('sale_id', 26);
            $t->string('variant_id', 26);
            $t->integer('sale_price_minor');
            $t->integer('cap_qty')->default(0);
            $t->integer('sold_qty')->default(0);
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->unique(['sale_id', 'variant_id'], 'uq_flash_item');
            $t->index('variant_id');
        });
    }
    public function down(): void
    {
        Schema::drop('flash_sale_items');
        Schema::drop('flash_sales');
    }
};
