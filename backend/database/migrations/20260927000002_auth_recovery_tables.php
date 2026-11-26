<?php
declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('auth_recovery_tokens', function (Blueprint $t): void {
            $t->string('id', 26); $t->integer('user_id'); $t->string('email', 255); $t->string('purpose', 32);
            $t->string('token_hash', 128); $t->datetime('expires_at'); $t->datetime('consumed_at')->nullable(); $t->datetime('created_at')->nullable();
            $t->primary('id'); $t->unique('token_hash'); $t->index(['email', 'purpose']); $t->index(['user_id', 'purpose']);
        });
        Schema::table('users', function (Blueprint $t): void {
            $t->datetime('email_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::drop('auth_recovery_tokens');
        Schema::table('users', function (Blueprint $t): void { $t->dropColumn('email_verified_at'); });
    }
};
