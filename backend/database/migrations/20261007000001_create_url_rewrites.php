<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('url_rewrites', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('request_path', 200);
            $t->string('target_path', 500);
            $t->integer('redirect_type')->default(301);
            $t->string('store_id', 64)->default('default');
            $t->string('channel_id', 64)->default('web');
            $t->string('created_by', 26)->nullable();
            $t->datetime('created_at')->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->unique(['request_path', 'store_id', 'channel_id']);
            $t->index(['store_id', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::drop('url_rewrites');
    }
};
