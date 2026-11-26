<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

// Auth identity follows skeleton INT convention (JWT sub/ver are ints).
return new class {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->default('');
            $t->string('email');
            $t->string('password');
            $t->string('role', 32)->default('viewer');
            $t->string('status', 20)->default('active');
            $t->integer('token_version')->default(1);
            $t->timestamps();
            $t->unique('email');
        });
        Schema::create('refresh_tokens', function (Blueprint $t) {
            $t->id();
            $t->integer('user_id');
            $t->string('jti');
            $t->integer('revoked')->default(0);
            $t->datetime('expires_at')->nullable();
            $t->timestamps();
            $t->unique('jti');
            $t->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::drop('refresh_tokens');
        Schema::drop('users');
    }
};
