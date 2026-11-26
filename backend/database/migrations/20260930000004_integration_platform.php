<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('integration_endpoints', function (Blueprint $t) {
            $t->string('id', 26); $t->string('scope_key', 255); $t->string('url', 2048);
            $t->string('secret_hash', 64); $t->string('secret_ciphertext', 255); $t->string('status', 24)->default('active');
            $t->string('event_filters', 2000)->nullable(); $t->datetime('created_at')->nullable(); $t->datetime('rotated_at')->nullable();
            $t->primary('id'); $t->index(['scope_key', 'status']);
        });
        Schema::create('integration_keys', function (Blueprint $t) {
            $t->string('id', 26); $t->string('public_key', 80); $t->string('secret_hash', 64);
            $t->string('scope_key', 255); $t->string('scopes', 2000); $t->string('status', 24)->default('active');
            $t->datetime('created_at')->nullable(); $t->datetime('revoked_at')->nullable(); $t->primary('id');
            $t->unique('public_key'); $t->index(['scope_key', 'status']);
        });
        Schema::create('integration_deliveries', function (Blueprint $t) {
            $t->string('id', 26); $t->string('endpoint_id', 26); $t->string('event_id', 64); $t->string('state', 24)->default('pending');
            $t->integer('attempts')->default(0); $t->datetime('available_at')->nullable(); $t->datetime('delivered_at')->nullable();
            $t->integer('response_status')->nullable(); $t->text('last_error')->nullable(); $t->primary('id');
            $t->unique(['endpoint_id', 'event_id']); $t->index(['state', 'available_at']);
        });
    }
    public function down(): void { Schema::drop('integration_deliveries'); Schema::drop('integration_keys'); Schema::drop('integration_endpoints'); }
};
