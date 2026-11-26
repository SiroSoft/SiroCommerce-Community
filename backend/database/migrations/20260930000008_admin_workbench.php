<?php
declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('admin_saved_views', function (Blueprint $t): void {
            $t->string('id', 26); $t->integer('user_id'); $t->string('store_id', 64); $t->string('channel_id', 64);
            $t->string('resource', 64); $t->string('name', 128); $t->text('filters_json'); $t->text('columns_json')->nullable();
            $t->datetime('created_at'); $t->datetime('updated_at'); $t->primary('id'); $t->index(['user_id', 'store_id', 'channel_id', 'resource']);
        });
        Schema::create('admin_permission_sets', function (Blueprint $t): void {
            $t->string('role', 32); $t->text('permissions_json'); $t->datetime('updated_at'); $t->primary('role');
        });
        Schema::create('admin_approval_workflows', function (Blueprint $t): void {
            $t->string('id', 26); $t->string('store_id', 64); $t->string('channel_id', 64); $t->string('name', 128);
            $t->string('resource', 64); $t->string('action', 64); $t->text('steps_json'); $t->integer('active')->default(1);
            $t->datetime('created_at'); $t->datetime('updated_at'); $t->primary('id'); $t->index(['store_id', 'channel_id', 'resource']);
        });
        Schema::create('admin_approval_requests', function (Blueprint $t): void {
            $t->string('id', 26); $t->string('workflow_id', 26); $t->string('store_id', 64); $t->string('channel_id', 64);
            $t->string('resource', 64); $t->string('resource_id', 128); $t->text('payload_json')->nullable(); $t->string('state', 24)->default('pending');
            $t->integer('requested_by'); $t->integer('approved_by')->nullable(); $t->datetime('created_at'); $t->datetime('updated_at');
            $t->primary('id'); $t->index(['store_id', 'channel_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::drop('admin_approval_requests'); Schema::drop('admin_approval_workflows');
        Schema::drop('admin_permission_sets'); Schema::drop('admin_saved_views');
    }
};
