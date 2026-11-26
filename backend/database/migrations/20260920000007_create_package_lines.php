<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('package_lines', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('package_id', 26);
            $t->string('fulfillment_line_id', 26);
            $t->integer('qty');
            $t->primary('id');
            $t->index('package_id');
        });
    }

    public function down(): void { Schema::drop('package_lines'); }
};
