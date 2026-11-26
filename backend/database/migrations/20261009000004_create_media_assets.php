<?php
declare(strict_types=1);
use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;
return new class {
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('url', 512);
            $t->string('storage_path', 512)->nullable();
            $t->string('type', 16);
            $t->string('mime_type', 128)->nullable();
            $t->integer('size_bytes')->default(0);
            $t->integer('width')->nullable();
            $t->integer('height')->nullable();
            $t->string('title', 200)->nullable();
            $t->string('alt', 200)->nullable();
            $t->string('created_by', 26)->nullable();
            $t->datetime('created_at')->nullable();
            $t->primary('id');
            $t->index('type');
            $t->index('created_at');
        });
    }
    public function down(): void
    {
        Schema::drop('media_assets');
    }
};
