<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Modules\B2B\Application\B2BQuoteService;
use App\Modules\Platform\Infrastructure\B2BAdapter;
use Siro\Core\Database;
use Tests\TestCase;

final class B2BQuoteLifecycleTest extends TestCase
{
    public function testQuoteTransitionsAreImmutableIdempotentAndConvertOnce(): void
    {
        $pdo=Database::connection();
        $pdo->prepare('INSERT INTO companies (id,name,status) VALUES (?,?,?)')->execute(['quote-co','Quote Co','active']);
        $pdo->prepare('INSERT INTO company_members (id,company_id,user_id,role,status) VALUES (?,?,?,?,?)')->execute(['quote-member','quote-co',7,'buyer','active']);
        $pdo->prepare('INSERT INTO company_members (id,company_id,user_id,role,status) VALUES (?,?,?,?,?)')->execute(['quote-approver','quote-co',8,'approver','active']);
        $pdo->prepare('INSERT INTO company_approval_scopes (id,company_id,scope,active,approval_limit_minor) VALUES (?,?,?,?,?)')->execute(['quote-scope','quote-co','quote.approve',1,10000]);
        $pdo->prepare('INSERT INTO company_credit_accounts (id,company_id,currency,limit_minor,status) VALUES (?,?,?,?,?)')->execute(['quote-credit','quote-co','VND',10000,'active']);
        $pdo->prepare('INSERT INTO company_catalogs (id,company_id,code,name,currency,scope,state) VALUES (?,?,?,?,?,?,?)')->execute(['quote-catalog','quote-co','Q','Q','VND','company','active']);
        $pdo->prepare('INSERT INTO company_catalog_items (id,catalog_id,variant_id,minimum_qty) VALUES (?,?,?,?)')->execute(['quote-item','quote-catalog','variant-q',1]);
        $pdo->prepare('INSERT INTO company_negotiated_prices (id,company_id,variant_id,currency,unit_price_minor,minimum_qty,state,evidence_json) VALUES (?,?,?,?,?,?,?,?)')->execute(['quote-price','quote-co','variant-q','VND',100,1,'active','{}']);
        $service=new B2BQuoteService(new B2BAdapter());
        $quote=$service->create('quote-co',7,'VND',[['variant_id'=>'variant-q','qty'=>2]],'quote-create');
        $service->transition($quote['id'],7,'submit','quote-submit');
        try { $service->transition($quote['id'],7,'approve','quote-buyer-approve'); self::fail('A buyer must not approve a quote.'); } catch (\RuntimeException $e) { self::assertSame('User cannot approve for this company', $e->getMessage()); }
        $service->transition($quote['id'],8,'approve','quote-approve');
        $service->transition($quote['id'],7,'accept','quote-accept');
        $converted=$service->convert($quote['id'],7,'quote-convert');
        self::assertSame('converted',$converted['to_state']);
        self::assertSame($converted,$service->convert($quote['id'],7,'quote-convert'));
        self::assertSame(4,(int)$pdo->query('SELECT COUNT(*) FROM b2b_quote_revisions WHERE quote_id=' . $pdo->quote((string)$quote['id']))->fetchColumn());
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    }
}
