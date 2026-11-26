<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('cms_pages', function (Blueprint $t): void {
            $t->dropUnique('uq_cms_pages_slug_locale');
            $t->unique(['store_id', 'channel_id', 'slug', 'locale', 'currency'], 'uq_cms_pages_scope_identity');
        });
    }

    public function down(): void
    {
        Schema::table('cms_pages', function (Blueprint $t): void {
            $t->dropUnique('uq_cms_pages_scope_identity');
            $t->unique(['slug', 'locale'], 'uq_cms_pages_slug_locale');
        });
    }
};
