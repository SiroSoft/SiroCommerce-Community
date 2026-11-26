<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('tax_rules', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('code', 64);
            $t->string('destination_country', 2)->nullable();
            $t->string('destination_region', 64)->nullable();
            $t->string('destination_postal_code', 32)->nullable();
            $t->string('tax_class', 32);
            $t->integer('rate_basis_points');
            $t->string('source', 255)->nullable();
            $t->text('evidence')->nullable();
            $t->integer('priority')->default(0);
            $t->string('state', 16)->default('active');
            $t->datetime('effective_at')->nullable();
            $t->datetime('expires_at')->nullable();
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->unique('code');
            $t->index(['destination_country', 'destination_region', 'tax_class', 'state'], 'idx_tax_rule_destination');
        });
    }

    public function down(): void
    {
        Schema::drop('tax_rules');
    }
};
