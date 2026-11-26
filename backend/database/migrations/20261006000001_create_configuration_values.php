<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('configuration_values', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('group_key', 64);
            $t->string('section_key', 64);
            $t->string('field_key', 64);
            $t->string('store_id', 64)->default('default');
            $t->string('channel_id', 64)->default('web');
            $t->text('value');
            $t->integer('version')->default(1);
            $t->string('updated_by', 26)->nullable();
            $t->datetime('updated_at')->nullable();
            $t->primary('id');
            $t->unique(['group_key', 'section_key', 'field_key', 'store_id', 'channel_id'], 'uq_config_values');
            $t->index(['group_key', 'section_key'], 'idx_config_group_section');
        });
    }

    public function down(): void
    {
        Schema::drop('configuration_values');
    }
};
