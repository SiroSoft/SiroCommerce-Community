<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('product_options', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('product_id', 26);
            $t->string('name', 128);
            $t->integer('sort')->default(0);
            $t->timestamps();
            $t->primary('id');
            $t->unique(['product_id', 'name']);
            $t->index('product_id');
        });
        Schema::create('product_option_values', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('option_id', 26);
            $t->string('value', 255);
            $t->integer('sort')->default(0);
            $t->timestamps();
            $t->primary('id');
            $t->unique(['option_id', 'value']);
            $t->index('option_id');
        });
        Schema::table('product_images', function (Blueprint $t) {
            $t->string('type', 16)->default('image');
            $t->string('mime_type', 128)->nullable();
            $t->integer('width')->nullable();
            $t->integer('height')->nullable();
            $t->integer('size_bytes')->nullable();
            $t->string('checksum', 128)->nullable();
            $t->string('title', 255)->nullable();
            $t->string('role', 32)->default('gallery');
            $t->string('storage_path', 512)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropColumn('product_images', 'type');
        Schema::dropColumn('product_images', 'role');
        Schema::dropColumn('product_images', 'title');
        Schema::dropColumn('product_images', 'checksum');
        Schema::dropColumn('product_images', 'size_bytes');
        Schema::dropColumn('product_images', 'height');
        Schema::dropColumn('product_images', 'width');
        Schema::dropColumn('product_images', 'mime_type');
        Schema::drop('product_option_values');
        Schema::drop('product_options');
    }
};
