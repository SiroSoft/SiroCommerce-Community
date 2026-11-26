<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('ext_social_share', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('store_id', 64);
            $t->string('channel_id', 64);
            $t->boolean('enabled')->default(false);
            $t->string('networks', 512)->default('[]');
            $t->string('label', 60)->default('Share this product');
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->unique(['store_id', 'channel_id']);
            $t->index(['store_id', 'channel_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::drop('ext_social_share');
    }
};
