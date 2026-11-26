<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('shipping_shipments', function (Blueprint $t) {
            $t->text('packages_json')->nullable();
            $t->string('last_error', 1000)->nullable();
            $t->integer('attempts')->default(0);
        });
        Schema::table('shipping_tracking_events', function (Blueprint $t) {
            $t->string('provider', 64)->default('dhl');
            $t->text('headers_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shipping_tracking_events', function (Blueprint $t) { $t->dropColumn('headers_json'); $t->dropColumn('provider'); });
        Schema::table('shipping_shipments', function (Blueprint $t) { $t->dropColumn('attempts'); $t->dropColumn('last_error'); $t->dropColumn('packages_json'); });
    }
};
