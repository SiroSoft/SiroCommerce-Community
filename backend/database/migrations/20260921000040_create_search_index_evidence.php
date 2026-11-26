<?php

declare(strict_types=1);

use Siro\Core\Schema;
use Siro\Core\DB\Blueprint;

return new class {
    public function up(): void
    {
        Schema::create('search_index_checkpoints', function (Blueprint $t): void { $t->string('index_name', 80); $t->string('cursor', 255)->nullable(); $t->string('version', 40); $t->string('state', 20)->default('queued'); $t->integer('processed')->default(0); $t->integer('failed')->default(0); $t->datetime('updated_at')->nullable(); $t->primary('index_name'); });
        Schema::create('search_index_dead_letters', function (Blueprint $t): void { $t->string('id', 26); $t->string('index_name', 80); $t->string('document_id', 80); $t->text('error'); $t->datetime('created_at')->nullable(); $t->datetime('resolved_at')->nullable(); $t->primary('id'); $t->index(['index_name', 'resolved_at']); });
        Schema::create('search_analytics', function (Blueprint $t): void { $t->string('id', 26); $t->string('query_hash', 64); $t->string('index_name', 80); $t->integer('result_count')->default(0); $t->integer('latency_ms')->default(0); $t->datetime('created_at')->nullable(); $t->primary('id'); $t->index(['index_name', 'created_at']); });
    }
    public function down(): void { Schema::dropIfExists('search_analytics'); Schema::dropIfExists('search_index_dead_letters'); Schema::dropIfExists('search_index_checkpoints'); }
};
