<?php
declare(strict_types=1);
use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('analytics_events', function (Blueprint $t) {
            $t->string('event_id', 64)->nullable();
            $t->integer('schema_version')->default(1);
            $t->text('payload_json')->nullable();
            $t->unique('event_id', 'uq_analytics_event_id');
            $t->index(['created_at', 'event'], 'idx_analytics_created_event');
        });
    }
    public function down(): void
    {
        Schema::table('analytics_events', function (Blueprint $t) {
            $t->dropIndex('idx_analytics_created_event');
            $t->dropUnique('uq_analytics_event_id');
            $t->dropColumn('payload_json');
            $t->dropColumn('schema_version');
            $t->dropColumn('event_id');
        });
    }
};
