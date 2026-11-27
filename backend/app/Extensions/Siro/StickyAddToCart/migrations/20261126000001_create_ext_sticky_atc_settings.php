<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('ext_sticky_atc_settings', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('store_id', 64);
            $t->string('channel_id', 64);
            $t->string('button_label', 40);
            $t->boolean('show_price')->default(true);
            $t->boolean('active')->default(false);
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->unique(['store_id', 'channel_id']);
            $t->index(['store_id', 'channel_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::drop('ext_sticky_atc_settings');
    }
};
