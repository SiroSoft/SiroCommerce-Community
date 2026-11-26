<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Modules\Promotion\Application\GiftCreditService;
use Tests\TestCase;

final class GiftCreditTest extends TestCase
{
    private function service(): GiftCreditService
    {
        return new GiftCreditService();
    }

    public function testIssueRedeemPartialReplayAndRollback(): void
    {
        $svc = $this->service();
        $issued = $svc->issueCredit(null, null, 'gift', 50000, 'VND', 'gift-key-1', null, null);
        self::assertSame(50000, $issued['balance_minor']);
        self::assertMatchesRegularExpression('/^GC-/', $issued['code']);
        // Replay same key returns the same credit, no duplicate.
        $replay = $svc->issueCredit(null, null, 'gift', 99999, 'VND', 'gift-key-1', null, null);
        self::assertSame($issued['id'], $replay['id']);
        self::assertSame(50000, $replay['balance_minor']);
        // Partial redeem.
        $after = $svc->redeemCode($issued['code'], null, 20000, 'VND', 'gift-key-2', 'o-1');
        self::assertSame(30000, $after['balance_minor']);
        // Overdraft rejected.
        try {
            $svc->redeemCode($issued['code'], null, 30001, 'VND', 'gift-key-3', null);
            self::fail('overdraft should throw');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Insufficient', $e->getMessage());
        }
        // Rollback restores.
        $rolled = $svc->rollback('gift-key-2');
        self::assertSame(50000, $rolled['balance_minor']);
        // Rollback replay is idempotent.
        $rolledAgain = $svc->rollback('gift-key-2');
        self::assertSame(50000, $rolledAgain['balance_minor']);
    }

    public function testOwnershipCurrencyExpiryAndVoid(): void
    {
        $svc = $this->service();
        $bound = $svc->issueCredit('MINE-1', 'cust-1', 'gift', 10000, 'VND', 'gift-key-10', null, null);
        try {
            $svc->redeemCode('MINE-1', 'cust-2', 1000, 'VND', 'gift-key-11', null);
            self::fail('wrong owner should throw');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('another customer', $e->getMessage());
        }
        self::assertNull($svc->balance('MINE-1', 'cust-2'));
        self::assertNotNull($svc->balance('MINE-1', 'cust-1'));
        try {
            $svc->redeemCode('MINE-1', 'cust-1', 1000, 'USD', 'gift-key-12', null);
            self::fail('currency mismatch should throw');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('currency', strtolower($e->getMessage()));
        }
        $expiring = $svc->issueCredit('OLD-1', null, 'gift', 5000, 'VND', 'gift-key-13', date('Y-m-d H:i:s', time() - 60), null);
        try {
            $svc->redeemCode('OLD-1', null, 1000, 'VND', 'gift-key-14', null);
            self::fail('expired should throw');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('expired', strtolower($e->getMessage()));
        }
        $voided = $svc->void($bound['id']);
        self::assertSame('voided', $voided['state']);
        try {
            $svc->redeemCode('MINE-1', 'cust-1', 1000, 'VND', 'gift-key-15', null);
            self::fail('voided should throw');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('not active', strtolower($e->getMessage()));
        }
    }

    public function testStoreCreditListing(): void
    {
        $svc = $this->service();
        $svc->issueCredit(null, 'cust-9', 'store_credit', 7000, 'VND', 'gift-key-20', null, null);
        $rows = $svc->list('cust-9', 'store_credit');
        self::assertCount(1, $rows);
        self::assertSame(7000, (int) $rows[0]['balance_minor']);
        self::assertCount(0, $svc->list('cust-other', 'store_credit'));
    }
}
