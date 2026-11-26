<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $t): void {
            // Add versioning columns if not exists (Blueprint handles ignore if exists via try)
            try { $t->integer('version')->default(1); } catch (\Throwable $e) {}
            try { $t->string('updated_by', 26)->nullable(); } catch (\Throwable $e) {}
        });
        Schema::create('coupon_versions', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('coupon_id', 26);
            $t->integer('version');
            $t->string('code', 64);
            $t->text('rules');
            $t->string('actor_id', 26)->nullable();
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->index('coupon_id');
            $t->unique(['coupon_id', 'version']);
        });
        Schema::create('coupon_bulk_jobs', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('actor_id', 26);
            $t->string('status', 16)->default('pending');
            $t->string('pattern', 64);
            $t->integer('count');
            $t->integer('start')->default(0);
            $t->text('rules');
            $t->text('result_json')->nullable();
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::drop('coupon_bulk_jobs');
        Schema::drop('coupon_versions');
        Schema::table('coupons', function (Blueprint $t): void {
            try { $t->dropColumn('updated_by'); } catch (\Throwable $e) {}
            try { $t->dropColumn('version'); } catch (\Throwable $e) {}
        });
    }
};
