<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $t) {
            $t->bigint('id');
            $t->string('job', 255);
            $t->text('data');
            $t->integer('attempts')->default(0);
            $t->integer('max_attempts')->default(3);
            $t->integer('priority')->default(0);
            $t->integer('timeout')->default(120);
            $t->bigint('available_at')->default(0);
            $t->bigint('locked_until')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->primary('id');
            $t->index(['available_at', 'locked_until']);
        });
        Schema::create('failed_jobs', function (Blueprint $t) {
            $t->bigint('id');
            $t->string('job', 255);
            $t->text('data');
            $t->text('error');
            $t->timestamp('failed_at')->useCurrent();
            $t->primary('id');
        });
        // MySQL needs an explicit AUTO_INCREMENT. The guard keeps the migration
        // replayable: an isolated rehearsal binds its own driver and skips this.
        if (self::driver() === 'mysql') {
            \Siro\Core\Database::connection()->exec('ALTER TABLE jobs MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
            \Siro\Core\Database::connection()->exec('ALTER TABLE failed_jobs MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
        }
    }

    private static function driver(): string
    {
        try {
            return strtolower((string) \Siro\Core\Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable) {
            return '';
        }
    }

    public function down(): void
    {
        Schema::drop('failed_jobs');
        Schema::drop('jobs');
    }
};
