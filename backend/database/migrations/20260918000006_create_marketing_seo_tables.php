<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $t) {
            $t->string('id', 26);
            $t->string('code', 64);
            $t->text('rules')->nullable();
            $t->string('state', 20)->default('active');
            $t->primary('id');
            $t->unique('code');
        });
        Schema::create('seo_meta', function (Blueprint $t) {
            $t->string('entity_type', 32);
            $t->string('entity_id', 26);
            $t->string('title');
            $t->string('description')->default('');
            $t->string('canonical', 512)->nullable();
            $t->string('robots', 64)->default('index,follow');
            $t->string('og_image', 512)->nullable();
            $t->primary(['entity_type', 'entity_id']);
        });
        Schema::create('redirects', function (Blueprint $t) {
            $t->string('from_path', 512);
            $t->string('to_path', 512);
            $t->integer('code')->default(301);
            $t->primary('from_path');
        });
    }

    public function down(): void
    {
        Schema::drop('redirects');
        Schema::drop('seo_meta');
        Schema::drop('coupons');
    }
};
