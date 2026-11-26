<?php
declare(strict_types=1);
namespace App\Modules\B2B\Contracts;

interface B2BQuoteStore
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createQuote(array $data): array;
    /** @return array<string,mixed>|null */
    public function quote(string $quoteId, int $userId): ?array;
    /** @return array<string,mixed>|null */
    public function quoteTransitionByKey(string $key): ?array;
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function transitionQuote(array $data): array;
    /** @return array<string,mixed> */
    public function convertQuote(string $quoteId, int $userId, string $key): array;
}
