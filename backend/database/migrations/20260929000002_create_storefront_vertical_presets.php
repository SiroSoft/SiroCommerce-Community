<?php
declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('storefront_vertical_presets', function (Blueprint $t): void {
            $t->id();
            $t->string('key', 64);
            $t->string('name', 128);
            $t->string('version', 32);
            $t->string('status', 16)->default('active');
            $t->text('theme_defaults_json');
            $t->text('presentation_defaults_json');
            $t->text('homepage_defaults_json');
            $t->text('validation_schema_json');
            $t->timestamps();
            $t->unique(['key', 'version']);
            $t->index(['status', 'key']);
        });
    }

    public function down(): void
    {
        Schema::drop('storefront_vertical_presets');
    }
};
