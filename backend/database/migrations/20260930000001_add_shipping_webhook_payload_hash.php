<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('shipping_provider_evidence', function (Blueprint $t): void {
            $t->string('payload_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropColumn('shipping_provider_evidence', 'payload_hash');
    }
};
