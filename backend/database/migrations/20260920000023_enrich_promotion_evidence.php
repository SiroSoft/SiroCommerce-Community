<?php
declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('coupon_usages', function (Blueprint $t): void { $t->string('reservation_key', 128)->nullable(); $t->string('state', 20)->default('committed'); $t->datetime('updated_at')->nullable(); $t->unique('reservation_key'); $t->index(['coupon_id', 'state']); });
        Schema::table('pricing_snapshots', function (Blueprint $t): void { $t->text('promotion_evidence_json')->nullable(); });
        Schema::table('orders', function (Blueprint $t): void { $t->text('promotion_evidence_json')->nullable(); });
    }
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t): void { $t->dropColumn('promotion_evidence_json'); });
        Schema::table('pricing_snapshots', function (Blueprint $t): void { $t->dropColumn('promotion_evidence_json'); });
        Schema::table('coupon_usages', function (Blueprint $t): void { $t->dropUnique('uq_coupon_usages_reservation_key'); $t->dropIndex('idx_coupon_usages_coupon_id_state'); $t->dropColumn('updated_at'); $t->dropColumn('state'); $t->dropColumn('reservation_key'); });
    }
};
