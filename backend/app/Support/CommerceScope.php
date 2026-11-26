<?php

declare(strict_types=1);

namespace App\Support;

use Siro\Core\Request;

final class CommerceScope
{
    /** @var string[] */
    public const ALLOWED_LOCALES = ['en', 'vi', 'ja', 'de', 'zh'];
    /** @var string[] */
    public const ALLOWED_CURRENCIES = ['VND', 'USD', 'EUR', 'JPY', 'CNY'];

    public function __construct(
        public readonly string $storeId,
        public readonly string $channelId,
        public readonly string $locale,
        public readonly string $currency,
        public readonly ?string $tenantId = null,
        public readonly ?string $sellerId = null,
        public readonly ?string $siteId = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            self::value($request, 'store_id', 'X-Store-Id', static fn (string $value): bool => preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) === 1),
            self::value($request, 'channel_id', 'X-Channel-Id', static fn (string $value): bool => preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) === 1),
            self::value($request, 'locale', 'X-Locale', static fn (string $value): bool => in_array(strtolower($value), self::ALLOWED_LOCALES, true), true),
            self::value($request, 'currency', 'X-Currency', static fn (string $value): bool => in_array(strtoupper($value), self::ALLOWED_CURRENCIES, true), false, true),
            self::optionalValue($request, 'tenant_id', 'X-Tenant-Id'),
            self::optionalValue($request, 'seller_id', 'X-Seller-Id'),
            self::optionalValue($request, 'site_id', 'X-Site-Id'),
        );
    }

    /** @return array<string, string> */
    public function metadata(): array
    {
        $metadata = ['store_id' => $this->storeId, 'channel_id' => $this->channelId, 'locale' => $this->locale, 'currency' => $this->currency];
        if ($this->tenantId !== null) $metadata['tenant_id'] = $this->tenantId;
        if ($this->sellerId !== null) $metadata['seller_id'] = $this->sellerId;
        if ($this->siteId !== null) $metadata['site_id'] = $this->siteId;
        return $metadata;
    }

    public function key(): string
    {
        $prefix = $this->tenantId === null ? '' : $this->tenantId . ':';
        if ($this->sellerId !== null) $prefix .= $this->sellerId . ':';
        if ($this->siteId !== null) $prefix .= $this->siteId . ':';
        return $prefix . $this->storeId . ':' . $this->channelId . ':' . $this->locale . ':' . $this->currency;
    }

    /** @param callable(string): bool $valid */
    private static function value(Request $request, string $queryKey, string $headerKey, callable $valid, bool $lower = false, bool $upper = false): string
    {
        $header = trim((string) $request->header($headerKey, ''));
        $query = trim($request->queryString($queryKey, ''));
        if ($header !== '' && $query !== '' && strcasecmp($header, $query) !== 0) {
            throw new CommerceScopeException('Ambiguous commerce scope: ' . $queryKey . '.');
        }
        $value = $header !== '' ? $header : $query;
        if ($value === '') throw new CommerceScopeException('Explicit commerce scope is required: ' . $queryKey . '.');
        if (!$valid($value)) throw new CommerceScopeException('Unsupported commerce scope: ' . $queryKey . '.');
        return $lower ? strtolower($value) : ($upper ? strtoupper($value) : $value);
    }

    private static function optionalValue(Request $request, string $queryKey, string $headerKey): ?string
    {
        $header = trim((string) $request->header($headerKey, ''));
        $query = trim($request->queryString($queryKey, ''));
        if ($header !== '' && $query !== '' && strcasecmp($header, $query) !== 0) throw new CommerceScopeException('Ambiguous commerce scope: ' . $queryKey . '.');
        $value = $header !== '' ? $header : $query;
        if ($value === '') return null;
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) !== 1) throw new CommerceScopeException('Unsupported commerce scope: ' . $queryKey . '.');
        return $value;
    }
}
