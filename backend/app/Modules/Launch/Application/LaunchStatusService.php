<?php

declare(strict_types=1);

namespace App\Modules\Launch\Application;

use App\Modules\Search\Application\CatalogIndexSchema;
use App\Support\Mailer;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Http;

/**
 * Launch readiness checks for a fresh operator install.
 *
 * Every check is read-only, returns no secrets, and degrades to warn/fail
 * rather than throwing when an optional provider is unreachable.
 */
final class LaunchStatusService
{
    private const PAGE_SLUGS = ['shipping', 'returns', 'contact'];
    private const STATE_WEIGHTS = ['ok' => 1.0, 'warn' => 0.5, 'fail' => 0.0];

    public function __construct(private readonly ProviderStatusService $providers = new ProviderStatusService())
    {
    }

    /** @return array{ready: bool, score: int, checks: list<array{key: string, state: string, hint: string, href: string, weight: float, detail: string}>} */
    public function status(): array
    {
        $checks = [
            $this->storeConfigCheck(),
            $this->catalogCheck(),
            $this->paymentsCheck(),
            $this->shippingCheck(),
            $this->mailCheck(),
            $this->searchCheck(),
            $this->legalPagesCheck(),
            $this->domainTlsCheck(),
        ];

        $points = 0.0;
        $blocking = 0;
        foreach ($checks as $check) {
            $points += self::STATE_WEIGHTS[$check['state']] ?? 0.0;
            if ($check['state'] !== 'ok') {
                $blocking++;
            }
        }

        return [
            'ready' => $blocking === 0,
            'score' => (int) round(($points / count($checks)) * 100),
            'checks' => $checks,
        ];
    }

    /** @return array{key: string, state: string, hint: string, href: string, weight: float, detail: string} */
    private function storeConfigCheck(): array
    {
        $total = $this->count('SELECT COUNT(*) FROM storefront_configs');
        $published = $this->count("SELECT COUNT(*) FROM storefront_configs WHERE status = 'published' AND version >= 1");
        if ($published > 0) {
            return $this->check('store_config', 'ok', 'Storefront theme is published.', '/storefront/theme', 'published configs: ' . $published);
        }
        if ($total > 0) {
            return $this->check('store_config', 'warn', 'Publish the storefront theme.', '/storefront/theme', 'config exists but is not published');
        }
        return $this->check('store_config', 'fail', 'Publish the storefront theme.', '/storefront/theme', 'no storefront configuration');
    }

    /** @return array{key: string, state: string, hint: string, href: string, weight: float, detail: string} */
    private function catalogCheck(): array
    {
        $active = $this->count("SELECT COUNT(*) FROM products WHERE status = 'active'");
        if ($active >= 3) {
            return $this->check('catalog', 'ok', 'Active products are available to sell.', '/products', 'active products: ' . $active);
        }
        if ($active >= 1) {
            return $this->check('catalog', 'warn', 'Add a few more active products so collections look complete.', '/products', 'active products: ' . $active);
        }
        return $this->check('catalog', 'fail', 'Add at least one active product.', '/products', 'active products: 0');
    }

    /** @return array{key: string, state: string, hint: string, href: string, weight: float, detail: string} */
    private function paymentsCheck(): array
    {
        $provider = $this->providers->paymentProvider();
        $mode = $this->providers->paymentMode();
        $valid = in_array($provider, ['cod', 'bank_transfer', 'stripe', 'paypal', 'adyen', 'authorize_net', 'square', 'vnpay', 'momo'], true);
        if (!$valid) {
            return $this->check('payments', 'fail', 'Set a supported PAYMENT_DEFAULT_PROVIDER.', '/settings', 'provider: ' . ($provider === '' ? 'missing' : $provider));
        }
        if (in_array($provider, ['cod', 'bank_transfer'], true)) {
            return $this->check('payments', 'ok', 'Cash or bank-transfer payment is configured.', '/settings', 'provider: ' . $provider . ', mode: ' . $mode);
        }
        $configured = $this->providers->paymentConfigured($provider);
        $production = strtolower(trim((string) Env::get('APP_ENV', 'local'))) === 'production';
        if (!$configured) {
            return $this->check(
                'payments',
                $production ? 'fail' : 'warn',
                'Set the credentials for the ' . $provider . ' payment provider.',
                '/settings',
                'provider: ' . $provider . ', credentials: missing',
            );
        }
        if (!in_array($mode, ['sandbox', 'production'], true)) {
            return $this->check('payments', 'fail', 'Set PAYMENT_MODE to sandbox or production.', '/settings', 'provider: ' . $provider . ', mode invalid');
        }
        if ($mode !== 'production') {
            return $this->check('payments', 'warn', 'Switch PAYMENT_MODE to production before launch.', '/settings', 'provider: ' . $provider . ', mode: sandbox');
        }
        return $this->check('payments', 'ok', 'Payment provider is configured for production.', '/settings', 'provider: ' . $provider . ', credentials: present');
    }

    /** @return array{key: string, state: string, hint: string, href: string, weight: float, detail: string} */
    private function shippingCheck(): array
    {
        $provider = $this->providers->shippingProvider();
        if ($provider === 'native') {
            return $this->check('shipping', 'ok', 'Native shipping rates are configured.', '/settings', 'provider: native');
        }
        if ($provider !== 'dhl') {
            return $this->check('shipping', 'fail', 'Configure DHL/GHN or native shipping rates.', '/settings', 'provider: ' . ($provider === '' ? 'missing' : $provider));
        }
        if (!$this->providers->shippingConfigured('dhl')) {
            return $this->check('shipping', 'warn', 'Set DHL_API_URL, DHL_API_KEY and DHL_API_SECRET.', '/settings', 'provider: dhl, credentials: missing');
        }
        $mode = strtolower(trim((string) Env::get('DHL_MODE', 'sandbox')));
        if ($mode !== 'api') {
            return $this->check('shipping', 'warn', 'Switch DHL_MODE to api before launch.', '/settings', 'provider: dhl, mode: ' . $mode);
        }
        return $this->check('shipping', 'ok', 'DHL live rates are configured.', '/settings', 'provider: dhl, mode: api');
    }

    /** @return array{key: string, state: string, hint: string, href: string, weight: float, detail: string} */
    private function mailCheck(): array
    {
        if (Mailer::isConfigured()) {
            return $this->check('mail', 'ok', 'Transactional email is configured.', '/settings', 'mail transport configured');
        }
        return $this->check('mail', 'warn', 'Set MAIL_* for transactional email.', '/settings', 'mail transport is silent');
    }

    /** @return array{key: string, state: string, hint: string, href: string, weight: float, detail: string} */
    private function searchCheck(): array
    {
        $provider = strtolower(trim((string) Env::get('CATALOG_SEARCH_PROVIDER', 'native')));
        if ($provider === 'native' || $provider === '') {
            return $this->check('search', 'ok', 'Native SQL search is active; configure Meilisearch for larger catalogs.', '/search-seo', 'provider: native');
        }
        if (!$this->providers->searchConfigured($provider)) {
            return $this->check('search', 'warn', 'Set SEARCH_URL and SEARCH_API_KEY for the configured search provider.', '/search-seo', 'provider: ' . $provider . ', configured: false');
        }
        if (!in_array($provider, ['meili', 'meilisearch'], true)) {
            return $this->check('search', 'warn', 'Search provider is configured; index count is not available.', '/search-seo', 'provider: ' . $provider);
        }
        $documents = $this->meiliDocumentCount();
        if ($documents === null) {
            return $this->check('search', 'warn', 'Meilisearch is configured but the index count could not be read; native fallback stays active.', '/search-seo', 'provider: meili, index: unreachable');
        }
        if ($documents < 1) {
            return $this->check('search', 'warn', 'Run the catalog indexer to populate Meilisearch.', '/search-seo', 'provider: meili, documents: 0');
        }
        return $this->check('search', 'ok', 'Meilisearch index is populated.', '/search-seo', 'provider: meili, documents: ' . $documents);
    }

    /** @return array{key: string, state: string, hint: string, href: string, weight: float, detail: string} */
    private function legalPagesCheck(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::PAGE_SLUGS), '?'));
        $published = $this->count(
            "SELECT COUNT(*) FROM cms_pages WHERE status = 'published' AND slug IN ({$placeholders})",
            self::PAGE_SLUGS,
        );
        if ($published >= count(self::PAGE_SLUGS)) {
            return $this->check('legal_pages', 'ok', 'Shipping, returns and contact pages are published.', '/pages', 'published pages: ' . $published . '/' . count(self::PAGE_SLUGS));
        }
        if ($published > 0) {
            return $this->check('legal_pages', 'warn', 'Publish the missing shipping, returns or contact page.', '/pages', 'published pages: ' . $published . '/' . count(self::PAGE_SLUGS));
        }
        return $this->check('legal_pages', 'fail', 'Publish shipping/returns/contact pages.', '/pages', 'published pages: 0/' . count(self::PAGE_SLUGS));
    }

    /** @return array{key: string, state: string, hint: string, href: string, weight: float, detail: string} */
    private function domainTlsCheck(): array
    {
        $url = trim((string) Env::get('APP_URL', ''));
        if ($url === '') {
            return $this->check('domain_tls', 'fail', 'Set APP_URL to the public HTTPS storefront URL.', '/settings', 'APP_URL: missing');
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return $this->check('domain_tls', 'fail', 'Set APP_URL to a valid public URL.', '/settings', 'APP_URL: invalid');
        }
        if (str_starts_with(strtolower($url), 'https://')) {
            return $this->check('domain_tls', 'ok', 'HTTPS is configured.', '/settings', 'APP_URL: https');
        }
        return $this->check('domain_tls', 'warn', 'Serve the storefront over HTTPS.', '/settings', 'APP_URL: http');
    }

    /** @return array{key: string, state: string, hint: string, href: string, weight: float, detail: string} */
    private function check(string $key, string $state, string $hint, string $href, string $detail): array
    {
        return [
            'key' => $key,
            'state' => $state,
            'hint' => $hint,
            'href' => $href,
            'weight' => self::STATE_WEIGHTS[$state],
            'detail' => $detail,
        ];
    }

    /** @param array<int, mixed> $params */
    private function count(string $sql, array $params = []): int
    {
        try {
            $rows = Database::select($sql, $params);
            $first = $rows[0] ?? null;
            if (!is_array($first)) {
                return 0;
            }
            $value = array_values($first)[0] ?? 0;
            return is_numeric($value) ? (int) $value : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function meiliDocumentCount(): ?int
    {
        $base = trim((string) Env::get('SEARCH_URL', ''));
        $key = trim((string) Env::get('SEARCH_API_KEY', ''));
        if ($base === '' || $key === '' || filter_var($base, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) return null;
        // Meilisearch usually lives on the private compose network, which the
        // SSRF-guarded Http client refuses; this URL is operator-configured.
        $handle = curl_init(rtrim($base, '/') . '/indexes/' . rawurlencode(CatalogIndexSchema::INDEX) . '/stats');
        if ($handle === false) return null;
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT_MS => 1500,
            CURLOPT_TIMEOUT_MS => max(1000, min(3000, (int) Env::get('SEARCH_TIMEOUT_SECONDS', '2') * 1000)),
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($body) || $status < 200 || $status >= 300) return null;
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) return null;
        $documents = $decoded['numberOfDocuments'] ?? 0;
        return is_numeric($documents) ? (int) $documents : 0;
    }
}
