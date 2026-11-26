<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('ext_free_shipping_banner', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('store_id', 64);
            $t->string('channel_id', 64);
            $t->integer('threshold_minor')->default(0);
            $t->string('message', 280);
            $t->boolean('active')->default(false);
            $t->string('currency', 3);
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->unique(['store_id', 'channel_id']);
            $t->index(['store_id', 'channel_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::drop('ext_free_shipping_banner');
    }
};
