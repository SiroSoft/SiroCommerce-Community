<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Money;
use App\Modules\B2B\Application\B2BEnterpriseService;
use App\Modules\B2B\Application\B2BPurchaseOrderService;
use Siro\Core\Database;
use Tests\TestCase;

final class B2BPurchaseOrderTest extends TestCase
{
    public function testPurchaseOrderWorkflowIsIdempotentAndScopeLimited(): void
    {
        $this->seedCompany();
        $service = new B2BPurchaseOrderService();
        $po = $service->create('po-company', 41, [
            'po_number' => 'PO-BOUNDARY',
            'currency' => 'VND',
            'total_minor' => Money::MAX_MINOR,
        ], 'po-create-1');

        self::assertSame('draft', $po['state']);
        self::assertSame([
            'id' => $po['id'],
            'company_id' => 'po-company',
            'contract_id' => null,
            'po_number' => 'PO-BOUNDARY',
            'currency' => 'VND',
            'total_minor' => Money::MAX_MINOR,
            'state' => 'draft',
            'created_by' => 41,
            'idempotency_key' => 'po-create-1',
        ], array_intersect_key($service->create('po-company', 41, [
            'po_number' => 'PO-BOUNDARY',
            'currency' => 'VND',
            'total_minor' => Money::MAX_MINOR,
        ], 'po-create-1'), array_flip(['id', 'company_id', 'contract_id', 'po_number', 'currency', 'total_minor', 'state', 'created_by', 'idempotency_key'])));

        $service->transition((string) $po['id'], 41, 'submit', 'po-submit-1');
        self::assertSame('submitted', $this->state((string) $po['id']));

        try {
            $service->transition((string) $po['id'], 41, 'approve', 'po-submit-1');
            self::fail('A transition idempotency key must not be reused for another action.');
        } catch (\RuntimeException $e) {
            self::assertSame('Idempotency key conflicts with another purchase order', $e->getMessage());
        }

        $this->expectExceptionMessage('Purchase order exceeds approval limit');
        $service->transition((string) $po['id'], 42, 'approve', 'po-approve-1');
    }

    public function testPurchaseOrderRejectsSignedIntOverflow(): void
    {
        $this->seedCompany();
        $service = new B2BPurchaseOrderService();

        $this->expectExceptionMessage('Purchase order number, amount, currency, and Idempotency-Key are required');
        $service->create('po-company', 41, [
            'po_number' => 'PO-OVERFLOW',
            'currency' => 'VND',
            'total_minor' => Money::MAX_MINOR + 1,
        ], 'po-overflow-1');
    }

    public function testQuoteRejectsLineTotalThatWouldOverflowMySqlInt(): void
    {
        $this->seedCompany();
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO company_catalogs (id,company_id,code,name,currency,scope,state) VALUES (?,?,?,?,?,?,?)')
            ->execute(['po-catalog', 'po-company', 'PO', 'PO', 'VND', 'company', 'active']);
        $pdo->prepare('INSERT INTO company_catalog_items (id,catalog_id,variant_id,minimum_qty) VALUES (?,?,?,?)')
            ->execute(['po-item', 'po-catalog', 'po-variant', 1]);
        $pdo->prepare('INSERT INTO company_negotiated_prices (id,company_id,variant_id,currency,unit_price_minor,minimum_qty,state,evidence_json) VALUES (?,?,?,?,?,?,?,?)')
            ->execute(['po-price', 'po-company', 'po-variant', 'VND', Money::MAX_MINOR, 1, 'active', '{}']);

        $this->expectExceptionMessage('Quote amount exceeds the storage limit');
        (new B2BEnterpriseService())->createQuote('po-company', 41, 'VND', [
            ['variant_id' => 'po-variant', 'qty' => 2],
        ], 'po-quote-overflow');
    }

    private function seedCompany(): void
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO companies (id,name,status) VALUES (?,?,?)')->execute(['po-company', 'PO Company', 'active']);
        $pdo->prepare('INSERT INTO company_members (id,company_id,user_id,role,status) VALUES (?,?,?,?,?)')->execute(['po-member-41', 'po-company', 41, 'buyer', 'active']);
        $pdo->prepare('INSERT INTO company_members (id,company_id,user_id,role,status) VALUES (?,?,?,?,?)')->execute(['po-member-42', 'po-company', 42, 'approver', 'active']);
        $pdo->prepare('INSERT INTO company_approval_scopes (id,company_id,scope,active,approval_limit_minor) VALUES (?,?,?,?,?)')->execute(['po-scope', 'po-company', 'purchase_order.approve', 1, 100]);
    }

    private function state(string $id): string
    {
        $statement = Database::connection()->prepare('SELECT state FROM b2b_purchase_orders WHERE id=?');
        $statement->execute([$id]);
        return (string) $statement->fetchColumn();
    }
}
