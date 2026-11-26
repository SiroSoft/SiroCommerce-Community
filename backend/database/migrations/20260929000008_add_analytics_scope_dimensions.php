<?php
declare(strict_types=1);
use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('analytics_events', function (Blueprint $t): void {
            $t->string('locale', 10)->nullable();
            $t->string('currency', 3)->nullable();
            $t->index(['store_id', 'channel_id', 'locale', 'currency', 'event', 'created_at'], 'idx_analytics_funnel_scope');
        });
    }
    public function down(): void
    {
        Schema::table('analytics_events', function (Blueprint $t): void {
            $t->dropIndex('idx_analytics_funnel_scope');
            $t->dropColumn('currency');
            $t->dropColumn('locale');
        });
    }
};
