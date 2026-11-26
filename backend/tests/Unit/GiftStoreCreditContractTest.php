<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Platform\Infrastructure\FailClosedGiftStoreCredit;
use PHPUnit\Framework\TestCase;

final class GiftStoreCreditContractTest extends TestCase
{
    public function testUnsupportedOperationsFailClosed(): void
    {
        $credit = new FailClosedGiftStoreCredit();
        foreach (['issue', 'redeem', 'rollback', 'expire'] as $operation) {
            try {
                if ($operation === 'issue') $credit->issue('owner', 100, 'USD', 'key');
                elseif ($operation === 'redeem') $credit->redeem('credit', 'owner', 100, 'USD', 'key');
                elseif ($operation === 'rollback') $credit->rollback('key');
                else $credit->expire('credit', 'key');
                self::fail($operation . ' unexpectedly succeeded');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('unsupported', strtolower($exception->getMessage()));
            }
        }
    }
}
