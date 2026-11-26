<?php

declare(strict_types=1);

use Siro\Core\DB\Blueprint;
use Siro\Core\Schema;

return new class {
    public function up(): void
    {
        Schema::table('product_components', function (Blueprint $t): void {
            $t->string('substitution_policy', 16)->default('none');
            $t->text('substitution_variant_ids')->nullable();
        });
        Schema::table('digital_assets', function (Blueprint $t): void {
            $t->string('content_sha256', 64)->nullable();
            $t->string('content_type', 128)->nullable();
            $t->integer('size_bytes')->nullable();
            $t->datetime('revoked_at')->nullable();
            $t->string('malware_scan_status', 16)->default('pending');
            $t->datetime('malware_scanned_at')->nullable();
        });
        Schema::table('digital_downloads', function (Blueprint $t): void {
            $t->datetime('revoked_at')->nullable();
            $t->text('evidence_json')->nullable();
            $t->index('revoked_at');
        });
    }

    public function down(): void
    {
        // digital_assets owns the malware columns; digital_downloads only has revoked_at.
        Schema::table('digital_downloads', function (Blueprint $t): void {
            $t->dropIndex('idx_digital_downloads_revoked_at');
            $t->dropColumn('revoked_at');
            $t->dropColumn('evidence_json');
        });
        Schema::table('digital_assets', function (Blueprint $t): void {
            $t->dropColumn('content_sha256');
            $t->dropColumn('content_type');
            $t->dropColumn('size_bytes');
            $t->dropColumn('revoked_at');
            $t->dropColumn('malware_scan_status');
            $t->dropColumn('malware_scanned_at');
        });
        Schema::table('product_components', function (Blueprint $t): void {
            $t->dropColumn('substitution_policy');
            $t->dropColumn('substitution_variant_ids');
        });
    }
};
