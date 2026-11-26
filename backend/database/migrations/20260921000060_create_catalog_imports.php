<?php
declare(strict_types=1);
use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;
return new class {
    public function up(): void
    {
        Schema::create('catalog_imports', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('source_fingerprint', 64);
            $t->string('format', 8);
            $t->integer('schema_version')->default(1);
            $t->string('state', 16)->default('completed');
            $t->integer('rows_seen')->default(0);
            $t->integer('rows_imported')->default(0);
            $t->text('errors_json')->nullable();
            $t->datetime('created_at');
            $t->datetime('completed_at')->nullable();
            $t->primary('id');
            $t->unique('source_fingerprint');
        });
    }
    public function down(): void { Schema::drop('catalog_imports'); }
};
