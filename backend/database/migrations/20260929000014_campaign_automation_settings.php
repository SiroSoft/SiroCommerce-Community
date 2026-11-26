<?php
declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::create('campaign_automation_settings', function (Blueprint $t): void {
            $t->string('scope_key', 256);
            $t->integer('enabled')->default(0);
            $t->integer('max_messages_per_day')->default(1000);
            $t->integer('quiet_hours_start')->default(21);
            $t->integer('quiet_hours_end')->default(8);
            $t->string('allowed_channels_json', 128)->default('["email"]');
            $t->datetime('updated_at')->nullable();
            $t->string('updated_by', 255)->nullable();
            $t->primary('scope_key');
        });
    }

    public function down(): void { Schema::drop('campaign_automation_settings'); }
};
