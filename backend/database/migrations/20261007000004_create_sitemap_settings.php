<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('sitemap_settings', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('store_id', 64)->default('default');
            $t->string('channel_id', 64)->default('web');
            $t->text('settings_json');
            $t->integer('version')->default(1);
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->unique(['store_id', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::drop('sitemap_settings');
    }
};
