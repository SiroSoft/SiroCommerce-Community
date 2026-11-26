<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('ext_store_locations', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('store_id', 64);
            $t->string('channel_id', 64);
            $t->string('name', 120);
            $t->string('address', 300);
            $t->string('city', 80);
            $t->string('phone', 40)->nullable();
            $t->string('hours', 120)->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->boolean('active')->default(true);
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->index(['store_id', 'channel_id', 'active', 'created_at'], 'idx_ext_store_locator_scope_active');
        });
    }

    public function down(): void
    {
        Schema::drop('ext_store_locations');
    }
};
