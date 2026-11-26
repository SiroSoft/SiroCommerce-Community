<?php
declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('storefront_configs', function (Blueprint $t): void {
            $t->id();
            $t->string('store_id', 64);
            $t->string('channel_id', 64);
            $t->string('locale', 16);
            $t->string('currency', 8);
            $t->string('theme_key', 64);
            $t->string('vertical_key', 64);
            $t->string('status', 16)->default('draft');
            $t->integer('version')->default(1);
            $t->text('config_json');
            $t->datetime('scheduled_at')->nullable();
            $t->datetime('published_at')->nullable();
            $t->string('created_by', 64)->nullable();
            $t->string('updated_by', 64)->nullable();
            $t->timestamps();
            $t->unique(['store_id', 'channel_id', 'locale', 'currency'], 'uq_storefront_cfg_scope');
            $t->index(['status', 'store_id', 'channel_id']);
            $t->index(['store_id', 'channel_id', 'locale', 'currency', 'version'], 'idx_storefront_cfg_version');
        });
    }

    public function down(): void
    {
        Schema::drop('storefront_configs');
    }
};
