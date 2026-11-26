<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('bi_exports', function (Blueprint $t): void {
            $t->string('id', 26);
            $t->string('dataset', 32);
            $t->string('format', 8);
            $t->integer('schema_version')->default(1);
            $t->datetime('from_at');
            $t->datetime('to_at');
            $t->datetime('snapshot_at');
            $t->string('state', 16)->default('queued');
            $t->integer('rows_written')->default(0);
            $t->integer('batch_size')->default(100);
            $t->string('cursor_at', 32)->nullable();
            $t->string('cursor_id', 64)->nullable();
            $t->string('storage_path', 255)->nullable();
            $t->string('download_url', 512)->nullable();
            $t->text('last_error')->nullable();
            $t->datetime('completed_at')->nullable();
            $t->datetime('created_at');
            $t->datetime('updated_at');
            $t->primary('id');
            $t->index(['state', 'created_at']);
            $t->index(['dataset', 'from_at', 'to_at']);
        });
    }

    public function down(): void
    {
        Schema::drop('bi_exports');
    }
};
