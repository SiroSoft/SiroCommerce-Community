<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('stock_alerts', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('product_id', 26);
            $t->string('variant_id', 26)->nullable();
            $t->string('email', 160)->nullable();
            $t->string('customer_id', 26)->nullable();
            $t->string('unsubscribe_token', 64);
            $t->string('state', 16)->default('pending');
            $t->datetime('created_at')->nullable();
            $t->datetime('notified_at')->nullable();
            $t->primary('id');
            $t->unique('unsubscribe_token');
            $t->index(['product_id', 'state']);
            $t->index(['variant_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::drop('stock_alerts');
    }
};
