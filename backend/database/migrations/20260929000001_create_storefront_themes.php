<?php
declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('storefront_themes', function (Blueprint $t): void {
            $t->id();
            $t->string('key', 64);
            $t->string('name', 128);
            $t->string('version', 32);
            $t->integer('schema_version')->default(1);
            $t->string('status', 16)->default('active');
            $t->text('token_schema_json');
            $t->text('allowed_section_types_json');
            $t->text('defaults_json');
            $t->timestamps();
            $t->unique(['key', 'version']);
            $t->index(['status', 'key']);
        });
    }

    public function down(): void
    {
        Schema::drop('storefront_themes');
    }
};
