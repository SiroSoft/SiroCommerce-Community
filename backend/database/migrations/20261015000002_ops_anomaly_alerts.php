<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('ops_anomaly_alerts', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('kind', 32);
            $t->string('state', 16)->default('open');
            $t->integer('window_hours')->default(24);
            $t->integer('observed')->default(0);
            $t->integer('baseline_x100')->default(0);
            $t->integer('ratio_x100')->default(0);
            $t->datetime('first_seen_at')->nullable();
            $t->datetime('last_seen_at')->nullable();
            $t->datetime('acked_at')->nullable();
            $t->datetime('computed_at')->nullable();
            $t->primary('id');
            $t->unique('kind');
            $t->index(['state', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::drop('ops_anomaly_alerts');
    }
};
