<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Modules\B2B\Application\B2BEnterpriseService;
use Siro\Core\Database;
use Tests\TestCase;

final class B2BQuoteSafetyTest extends TestCase
{
    public function testEveryLineRequiresCatalogMembershipAndNegotiatedPriceWins(): void
    {
        $this->seedCompany('company-v9', 41);
        $this->seedCatalog('catalog-v9', 'company-v9', 'company');
        Database::connection()->prepare('INSERT INTO company_catalog_items (id,catalog_id,variant_id,minimum_qty) VALUES (?,?,?,?)')->execute(['item-v9', 'catalog-v9', 'variant-v9', 2]);
        Database::connection()->prepare('INSERT INTO company_negotiated_prices (id,company_id,variant_id,currency,unit_price_minor,minimum_qty,state,evidence_json) VALUES (?,?,?,?,?,?,?,?)')->execute(['price-v9', 'company-v9', 'variant-v9', 'VND', 777, 2, 'active', '{}']);

        $quote = (new B2BEnterpriseService())->createQuote('company-v9', 41, 'VND', [['variant_id' => 'variant-v9', 'qty' => 2]], 'quote-v9');
        self::assertSame(777, $quote['lines'][0]['unit_price_minor']);
        self::assertSame('negotiated', $quote['lines'][0]['pricing_source']);

        $this->expectException(\RuntimeException::class);
        (new B2BEnterpriseService())->createQuote('company-v9', 41, 'VND', [['variant_id' => 'not-catalogued', 'qty' => 1]], 'quote-v9-missing');
    }

    public function testSharedCatalogAndPaymentTermsRequireEvidence(): void
    {
        $this->seedCompany('company-shared', 42);
        $this->seedCatalog('catalog-shared', 'company-shared', 'shared');
        Database::connection()->prepare('INSERT INTO company_catalog_items (id,catalog_id,variant_id,minimum_qty) VALUES (?,?,?,?)')->execute(['item-shared', 'catalog-shared', 'variant-shared', 1]);
        Database::connection()->prepare('INSERT INTO company_negotiated_prices (id,company_id,variant_id,currency,unit_price_minor,minimum_qty,state,evidence_json) VALUES (?,?,?,?,?,?,?,?)')->execute(['price-shared', 'company-shared', 'variant-shared', 'VND', 100, 1, 'active', '{}']);
        Database::connection()->prepare('INSERT INTO company_credit_terms (id,company_id,payment_terms_days,evidence_json) VALUES (?,?,?,?)')->execute(['terms-v9', 'company-shared', 30, '{}']);

        $quote = (new B2BEnterpriseService())->createQuote('company-shared', 42, 'VND', [['variant_id' => 'variant-shared', 'qty' => 1]], 'quote-terms-v9', null, 'PO-42', 30);
        self::assertSame('PO-42', $quote['purchase_order_number']);
        self::assertSame(30, $quote['payment_terms_days']);
    }

    private function seedCompany(string $companyId, int $userId): void
    {
        Database::connection()->prepare('INSERT INTO companies (id,name,status) VALUES (?,?,?)')->execute([$companyId, $companyId, 'active']);
        Database::connection()->prepare('INSERT INTO company_members (id,company_id,user_id,role,status) VALUES (?,?,?,?,?)')->execute(['member-'.$userId, $companyId, $userId, 'buyer', 'active']);
    }

    private function seedCatalog(string $catalogId, string $companyId, string $scope): void
    {
        Database::connection()->prepare('INSERT INTO company_catalogs (id,company_id,code,name,currency,scope,state) VALUES (?,?,?,?,?,?,?)')->execute([$catalogId, $companyId, $catalogId, $catalogId, 'VND', $scope, 'active']);
    }
}
