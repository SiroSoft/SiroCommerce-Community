<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('extensions', function (Blueprint $t) {
            $t->string('id', 128);
            $t->string('version', 32);
            $t->string('state', 24)->default('discovered');
            $t->text('manifest_json');
            $t->text('last_error')->nullable();
            $t->datetime('installed_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
        });
    }

    public function down(): void
    {
        Schema::drop('extensions');
    }
};
