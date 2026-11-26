<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('cms_revisions', function (Blueprint $t): void {
            $t->string('idempotency_key', 128)->nullable();
            $t->unique('idempotency_key', 'uq_cms_revisions_idempotency');
        });
    }

    public function down(): void
    {
        Schema::table('cms_revisions', function (Blueprint $t): void {
            $t->dropUnique('uq_cms_revisions_idempotency');
            $t->dropColumn('idempotency_key');
        });
    }
};
