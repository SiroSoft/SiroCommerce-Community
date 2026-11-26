<?php
declare(strict_types=1);
namespace App\Modules\B2B\Application;

use App\Modules\B2B\Contracts\B2BQuoteStore;
use RuntimeException;

final class B2BQuoteService
{
    public function __construct(private readonly B2BQuoteStore $store) {}

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    public function create(string $companyId, int $userId, string $currency, array $lines, string $key, ?string $expiresAt = null, ?string $po = null, int $terms = 0): array
    {
        if ($key === '') throw new RuntimeException('Idempotency-Key is required');
        return $this->store->createQuote(['company_id'=>$companyId, 'user_id'=>$userId, 'currency'=>$currency, 'lines'=>$lines, 'idempotency_key'=>$key, 'expires_at'=>$expiresAt, 'purchase_order_number'=>$po, 'payment_terms_days'=>$terms]);
    }

    /** @return array<string, mixed> */
    public function transition(string $quoteId, int $userId, string $action, string $key, ?string $reason = null): array
    {
        if ($key === '') throw new RuntimeException('Idempotency-Key is required');
        if (!in_array($action, ['submit','approve','accept','expire'], true)) throw new RuntimeException('Invalid B2B quote transition');
        // Authorize replays before returning stored evidence for a known key.
        $this->show($quoteId, $userId);
        $existing = $this->store->quoteTransitionByKey($key);
        if ($existing !== null) return $existing;
        return $this->store->transitionQuote(['quote_id'=>$quoteId, 'user_id'=>$userId, 'action'=>$action, 'idempotency_key'=>$key, 'reason'=>$reason ?? '']);
    }

    /** @return array<string,mixed> */
    public function show(string $quoteId, int $userId): array
    {
        $quote = $this->store->quote($quoteId, $userId);
        if ($quote === null) throw new RuntimeException('B2B quote not found');
        return $quote;
    }

    /** @return array<string,mixed> */
    public function convert(string $quoteId, int $userId, string $key): array
    {
        if ($key === '') throw new RuntimeException('Idempotency-Key is required');
        // A replay key is not an authorization token.
        $this->show($quoteId, $userId);
        $existing = $this->store->quoteTransitionByKey($key);
        if ($existing !== null) return $existing;
        return $this->store->convertQuote($quoteId, $userId, $key);
    }
}
