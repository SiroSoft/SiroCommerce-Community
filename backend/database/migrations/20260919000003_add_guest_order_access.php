<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('guest_order_access', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('order_id', 32);
            $t->string('token_hash', 128);
            $t->datetime('expires_at')->nullable();
            $t->datetime('revoked_at')->nullable();
            $t->primary('id');
            $t->unique('order_id');
            $t->index('token_hash');
        });
    }

    public function down(): void
    {
        Schema::drop('guest_order_access');
    }
};
