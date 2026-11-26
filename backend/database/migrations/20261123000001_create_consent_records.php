<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('consent_records', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('session_hash', 64);
            $t->text('choices_json');
            $t->string('policy_version', 32);
            $t->string('ip_hash', 64)->nullable();
            $t->string('user_agent', 200)->nullable();
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->unique(['session_hash', 'policy_version']);
            $t->index('policy_version');
        });
    }

    public function down(): void
    {
        Schema::drop('consent_records');
    }
};
