<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\B2B\Application\B2BCommercialService;
use Siro\Core\Database;
use Tests\TestCase;

final class B2BCommercialControlsTest extends TestCase
{
    public function testTierValidationEnforcesTermsAndReplaysAuditEvidence(): void
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO companies (id,name,status) VALUES (?,?,?)')->execute(['commercial-co','Commercial Co','active']);
        $pdo->prepare('INSERT INTO company_members (id,company_id,user_id,role,status) VALUES (?,?,?,?,?)')->execute(['commercial-member','commercial-co',91,'buyer','active']);
        $service = new B2BCommercialService();

        $group = $service->createGroup('commercial-co', ['code' => 'north', 'name' => 'North']);
        self::assertSame('commercial-co', $group['company_id']);
        self::assertSame('approver', $service->setRolePolicy('commercial-co', 'approver', ['policy' => ['approve_quotes' => true], 'approval_limit_minor' => 5000])['role']);
        $list = $service->createPriceList('commercial-co', ['code' => 'standard', 'currency' => 'VND']);
        $service->addPriceTier((string) $list['id'], ['variant_id' => 'variant-commercial', 'minimum_qty' => 10, 'unit_price_minor' => 100]);
        $service->setTerms('commercial-co', ['minimum_order_minor' => 1000, 'minimum_order_qty' => 10, 'currency' => 'VND']);

        $result = $service->validateBulk('commercial-co', 91, [['variant_id' => 'variant-commercial', 'qty' => 10]], 'bulk-commercial');
        self::assertTrue($result['valid']);
        self::assertSame($result, $service->validateBulk('commercial-co', 91, [['variant_id' => 'variant-commercial', 'qty' => 1]], 'bulk-commercial'));
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM b2b_audit_events WHERE idempotency_key='bulk-commercial'")->fetchColumn());
    }

    public function testBulkValidationRejectsNonMemberBeforeReadingCustomerPricing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('User is not a company member');
        (new B2BCommercialService())->validateBulk('missing-company', 999, [], 'bulk-security');
    }
}
