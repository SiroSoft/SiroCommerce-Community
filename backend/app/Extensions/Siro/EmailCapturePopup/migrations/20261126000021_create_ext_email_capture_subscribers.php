<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('ext_email_capture_subscribers', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('store_id', 64);
            $t->string('channel_id', 64);
            $t->string('email', 254);
            $t->string('customer_id', 64)->nullable();
            $t->string('source', 32);
            $t->string('coupon_code', 64)->nullable();
            $t->datetime('consented_at')->nullable();
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->unique(['store_id', 'channel_id', 'email']);
            $t->index(['store_id', 'channel_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::drop('ext_email_capture_subscribers');
    }
};
