<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('ext_contact_widget', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('store_id', 64);
            $t->string('channel_id', 64);
            $t->boolean('enabled')->default(false);
            $t->string('zalo_url', 2048)->nullable();
            $t->string('whatsapp_url', 2048)->nullable();
            $t->string('email', 254)->nullable();
            $t->string('label', 40)->default('Contact us');
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->unique(['store_id', 'channel_id']);
            $t->index(['store_id', 'channel_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::drop('ext_contact_widget');
    }
};
