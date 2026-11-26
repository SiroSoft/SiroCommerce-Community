<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Modules\Vendor\Application\VendorPayoutService;
use App\Repositories\UserRepository;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class VendorPayoutsTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
        $stub = new \stdClass();
        $stub->router = $this->router;
        $app = $stub;
        require dirname(__DIR__, 2) . '/routes/api.php';
    }

    /** @param array<string, mixed> $body @param array<string, string> $headers @return array{status: int, json: mixed} */
    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $query = [];
        if (str_contains($path, '?')) {
            $parts = explode('?', $path, 2);
            $path = $parts[0];
            parse_str($parts[1] ?? '', $query);
        }
        $request = new Request($method, $path, $query, $headers, $body, '127.0.0.1');
        try {
            $response = $this->router->dispatch($request);
        } catch (ValidationException $e) {
            $response = $e->toResponse();
        }
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();
        /** @var array{status: int, json: mixed} $out */
        $out = ['status' => $response->statusCode(), 'json' => json_decode($output, true)];
        return $out;
    }

    /** @param array<string, string> $headers @return array{status: int, body: string, headers: array<string, string>} */
    private function callRaw(string $method, string $path, array $headers = []): array
    {
        $request = new Request($method, $path, [], $headers, [], '127.0.0.1');
        try {
            $response = $this->router->dispatch($request);
        } catch (ValidationException $e) {
            $response = $e->toResponse();
        }
        ob_start();
        $response->send();
        $body = (string) ob_get_clean();
        return ['status' => $response->statusCode(), 'body' => $body, 'headers' => $response->headers()];
    }

    /** @return array<string, string> */
    private function loginAs(string $email, string $role): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => $role, 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    /** @return array{headers: array<string, string>, id: string} */
    private function customerAccount(string $email): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $user = $auth->register(['name' => 'customer', 'email' => $email, 'password' => 'secret123', 'role' => 'customer']);
        $tokens = $auth->login($email, 'secret123');
        return [
            'headers' => ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'],
            'id' => (string) ($user['id'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $admin */
    private function createVendor(array $admin, int $bps, string $name = 'Acme Studio'): array
    {
        $created = $this->call('POST', '/api/admin/vendors', ['name' => $name, 'commission_bps' => $bps], $admin);
        $this->assertSame(201, $created['status']);
        return $created['json']['data'];
    }

    /** @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    private function createProduct(array $admin, string $slug, int $priceMinor, ?string $vendorId): array
    {
        $body = ['name' => ucfirst(str_replace('-', ' ', $slug)), 'slug' => $slug, 'price_minor' => $priceMinor, 'stock' => 50, 'sku' => strtoupper($slug) . '-1', 'status' => 'active'];
        if ($vendorId !== null) $body['vendor_id'] = $vendorId;
        $created = $this->call('POST', '/api/admin/products', $body, $admin);
        $this->assertSame(201, $created['status'], (string) json_encode($created['json']));
        return $created['json']['data'];
    }

    /** @param array<string, mixed> $vendor
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function placeAndPay(array $admin, array $product, int $qty, string $key): array
    {
        $variantId = (string) $product['variants'][0]['id'];
        $cart = $this->call('POST', '/api/carts', []);
        $this->assertSame(201, $cart['status']);
        $cartId = (string) $cart['json']['data']['id'];
        $cartToken = (string) $cart['json']['data']['access_token'];
        $line = $this->call('POST', '/api/carts/' . $cartId . '/lines', ['variant_id' => $variantId, 'qty' => $qty], ['X-Cart-Token' => $cartToken]);
        $this->assertSame(201, $line['status']);
        $placed = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId], [
            'Idempotency-Key' => $key,
            'X-Cart-Token' => $cartToken,
            'X-Store-Id' => 'default',
            'X-Channel-Id' => 'default',
            'X-Locale' => 'en',
            'X-Currency' => 'VND',
        ]);
        $this->assertSame(201, $placed['status'], (string) json_encode($placed['json']));
        $orderId = (string) $placed['json']['data']['id'];
        $paid = $this->call('POST', '/api/admin/orders/' . $orderId . '/transition', ['to' => 'paid'], $admin);
        $this->assertSame(200, $paid['status'], (string) json_encode($paid['json']));
        $order = $this->call('GET', '/api/admin/orders/' . $orderId, [], $admin);
        $this->assertSame(200, $order['status']);
        return $order['json']['data'];
    }

    /** @param array<string, mixed> $admin
     * @return array<int, array<string, mixed>>
     */
    private function ledgerEntries(array $admin, string $vendorId): array
    {
        $ledger = $this->call('GET', '/api/admin/vendors/' . $vendorId . '/ledger', [], $admin);
        $this->assertSame(200, $ledger['status'], (string) json_encode($ledger['json']));
        return $ledger['json']['data']['entries'];
    }

    public function testCommissionRoundingSnapshotImmutabilityAndSaleIdempotency(): void
    {
        $admin = $this->loginAs('payout-owner@shop.test', 'owner');
        $vendor = $this->createVendor($admin, 3333, 'Rounding Studio');
        $vendorId = (string) $vendor['id'];
        $product = $this->createProduct($admin, 'rounding-item', 251, $vendorId);

        $order = $this->placeAndPay($admin, $product, 1, 'payout-place-1');
        $entries = $this->ledgerEntries($admin, $vendorId);
        $this->assertCount(1, $entries);
        $this->assertSame('sale', $entries[0]['type']);
        $this->assertSame(251, $entries[0]['gross_minor']);
        $this->assertSame(84, $entries[0]['commission_minor']);
        $this->assertSame(167, $entries[0]['net_minor']);
        $this->assertSame('available', $entries[0]['state']);

        // Snapshot stays immutable when the vendor commission changes later.
        $this->assertSame(200, $this->call('PUT', '/api/admin/vendors/' . $vendorId, ['commission_bps' => 1000], $admin)['status']);
        $line = $order['lines'][0];
        $this->assertSame(84, $line['vendor_commission_minor']);
        $this->assertSame($vendorId, $line['vendor_id']);

        // Replaying the sale recording path must not duplicate the entry.
        $service = new VendorPayoutService();
        $this->assertSame(0, $service->recordSalesForOrder((string) $order['id']));
        $this->assertCount(1, $this->ledgerEntries($admin, $vendorId));

        $second = $this->placeAndPay($admin, $product, 1, 'payout-place-2');
        $entries = $this->ledgerEntries($admin, $vendorId);
        $this->assertCount(2, $entries);
        $newest = null;
        foreach ($entries as $entry) {
            if ((string) $entry['order_id'] === (string) $second['id']) { $newest = $entry; break; }
        }
        $this->assertNotNull($newest);
        $this->assertSame(25, $newest['commission_minor']);
        $this->assertSame(226, $newest['net_minor']);
        $this->assertSame(25, $second['lines'][0]['vendor_commission_minor']);
    }

    public function testRefundCreatesNegativeEntriesAndDoesNotDuplicate(): void
    {
        $admin = $this->loginAs('payout-owner2@shop.test', 'owner');
        $vendor = $this->createVendor($admin, 500, 'Refund Studio');
        $vendorId = (string) $vendor['id'];
        $product = $this->createProduct($admin, 'refund-item', 1000, $vendorId);
        $order = $this->placeAndPay($admin, $product, 2, 'payout-place-3');
        $orderId = (string) $order['id'];
        $lineId = (string) $order['lines'][0]['id'];

        $lineRefund = $this->call('POST', '/api/admin/orders/' . $orderId . '/refund-lines', [
            'lines' => [['order_line_id' => $lineId, 'qty' => 1]],
            'reason' => 'damaged',
        ], $admin + ['Idempotency-Key' => 'payout-refund-line-1']);
        $this->assertSame(200, $lineRefund['status'], (string) json_encode($lineRefund['json']));

        $entries = $this->ledgerEntries($admin, $vendorId);
        $this->assertCount(2, $entries);
        $refund = null;
        foreach ($entries as $entry) if ($entry['type'] === 'refund' && (string) $entry['order_line_id'] === $lineId) $refund = $entry;
        $this->assertNotNull($refund);
        $this->assertSame(-1000, $refund['gross_minor']);
        $this->assertSame(-50, $refund['commission_minor']);
        $this->assertSame(-950, $refund['net_minor']);

        $replay = $this->call('POST', '/api/admin/orders/' . $orderId . '/refund-lines', [
            'lines' => [['order_line_id' => $lineId, 'qty' => 1]],
            'reason' => 'damaged',
        ], $admin + ['Idempotency-Key' => 'payout-refund-line-1']);
        $this->assertSame(200, $replay['status']);
        $this->assertCount(2, $this->ledgerEntries($admin, $vendorId));

        $orderRefund = $this->call('POST', '/api/admin/orders/' . $orderId . '/refund', [
            'amount_minor' => 1000,
            'reason' => 'rest',
        ], $admin + ['Idempotency-Key' => 'payout-refund-order-1']);
        $this->assertSame(200, $orderRefund['status'], (string) json_encode($orderRefund['json']));
        $entries = $this->ledgerEntries($admin, $vendorId);
        $this->assertCount(3, $entries);
        $orderLevel = null;
        foreach ($entries as $entry) if ($entry['type'] === 'refund' && $entry['order_line_id'] === null) $orderLevel = $entry;
        $this->assertNotNull($orderLevel);
        $this->assertSame(-1000, $orderLevel['gross_minor']);
        $this->assertSame(-50, $orderLevel['commission_minor']);
        $this->assertSame(-950, $orderLevel['net_minor']);

        $ledger = $this->call('GET', '/api/admin/vendors/' . $vendorId . '/ledger', [], $admin);
        $this->assertSame(0, $ledger['json']['data']['totals']['net']);
    }

    public function testPayoutWindowIdempotencyMarkPaidAndBalances(): void
    {
        $admin = $this->loginAs('payout-owner3@shop.test', 'owner');
        $vendor = $this->createVendor($admin, 500, 'Batch Studio');
        $vendorId = (string) $vendor['id'];
        $product = $this->createProduct($admin, 'batch-item', 2000, $vendorId);
        $this->placeAndPay($admin, $product, 1, 'payout-place-4');

        $ledger = $this->call('GET', '/api/admin/vendors/' . $vendorId . '/ledger', [], $admin);
        $this->assertSame(1900, $ledger['json']['data']['totals']['available']);
        $this->assertSame(0, $ledger['json']['data']['totals']['pending']);

        $window = ['period_start' => date('Y-m-d', time() - 86400), 'period_end' => date('Y-m-d', time() + 86400)];
        $created = $this->call('POST', '/api/admin/vendors/' . $vendorId . '/payouts', $window, $admin + ['Idempotency-Key' => 'payout-batch-1']);
        $this->assertSame(201, $created['status'], (string) json_encode($created['json']));
        $payout = $created['json']['data'];
        $this->assertSame('pending', $payout['state']);
        $this->assertSame(1900, $payout['amount_minor']);

        $replayed = $this->call('POST', '/api/admin/vendors/' . $vendorId . '/payouts', $window, $admin + ['Idempotency-Key' => 'payout-batch-1']);
        $this->assertSame(201, $replayed['status']);
        $this->assertSame($payout['id'], $replayed['json']['data']['id']);

        $ledger = $this->call('GET', '/api/admin/vendors/' . $vendorId . '/ledger', [], $admin);
        $this->assertSame(0, $ledger['json']['data']['totals']['available']);
        $this->assertSame(1900, $ledger['json']['data']['totals']['pending']);

        $paid = $this->call('POST', '/api/admin/vendor-payouts/' . $payout['id'] . '/mark-paid', [], $admin);
        $this->assertSame(200, $paid['status'], (string) json_encode($paid['json']));
        $this->assertSame('paid', $paid['json']['data']['state']);
        $ledger = $this->call('GET', '/api/admin/vendors/' . $vendorId . '/ledger', [], $admin);
        $this->assertSame(0, $ledger['json']['data']['totals']['pending']);
        $this->assertSame(1900, $ledger['json']['data']['totals']['paid']);
        $this->assertSame(409, $this->call('POST', '/api/admin/vendor-payouts/' . $payout['id'] . '/mark-paid', [], $admin)['status']);

        // A separate sale/payout can be cancelled, returning entries to available.
        $product2 = $this->createProduct($admin, 'batch-item-2', 500, $vendorId);
        $this->placeAndPay($admin, $product2, 1, 'payout-place-5');
        $cancelCreated = $this->call('POST', '/api/admin/vendors/' . $vendorId . '/payouts', $window, $admin + ['Idempotency-Key' => 'payout-batch-2']);
        $this->assertSame(201, $cancelCreated['status'], (string) json_encode($cancelCreated['json']));
        $cancelPayout = $cancelCreated['json']['data'];
        $this->assertSame(475, $cancelPayout['amount_minor']);
        $cancelled = $this->call('POST', '/api/admin/vendor-payouts/' . $cancelPayout['id'] . '/cancel', [], $admin);
        $this->assertSame(200, $cancelled['status']);
        $this->assertSame('cancelled', $cancelled['json']['data']['state']);
        $ledger = $this->call('GET', '/api/admin/vendors/' . $vendorId . '/ledger', [], $admin);
        $this->assertSame(475, $ledger['json']['data']['totals']['available']);
        $this->assertSame(1900, $ledger['json']['data']['totals']['paid']);
        $this->assertSame(409, $this->call('POST', '/api/admin/vendor-payouts/' . $cancelPayout['id'] . '/cancel', [], $admin)['status']);
    }

    public function testPayoutWindowExcludesEntriesOutsideRange(): void
    {
        $admin = $this->loginAs('payout-owner4@shop.test', 'owner');
        $vendor = $this->createVendor($admin, 500, 'Window Studio');
        $vendorId = (string) $vendor['id'];
        $product = $this->createProduct($admin, 'window-item', 300, $vendorId);
        $order = $this->placeAndPay($admin, $product, 1, 'payout-place-6');
        $entries = $this->ledgerEntries($admin, $vendorId);
        $entry = \App\Models\VendorLedgerEntry::find((string) $entries[0]['id']);
        $this->assertNotNull($entry);
        $entry->update(['created_at' => '2020-01-01 10:00:00']);

        $narrow = $this->call('POST', '/api/admin/vendors/' . $vendorId . '/payouts', ['period_start' => date('Y-m-d'), 'period_end' => date('Y-m-d')], $admin + ['Idempotency-Key' => 'payout-window-narrow']);
        $this->assertSame(422, $narrow['status']);
        $wide = $this->call('POST', '/api/admin/vendors/' . $vendorId . '/payouts', ['period_start' => '2019-01-01', 'period_end' => '2021-01-01'], $admin + ['Idempotency-Key' => 'payout-window-wide']);
        $this->assertSame(201, $wide['status'], (string) json_encode($wide['json']));
        $this->assertSame(285, $wide['json']['data']['amount_minor']);
        $this->assertSame((string) $order['id'], (string) $entries[0]['order_id']);
    }

    public function testAuthorizationValidationAndSelfService(): void
    {
        $admin = $this->loginAs('payout-owner5@shop.test', 'owner');
        $vendor = $this->createVendor($admin, 500, 'Auth Studio');
        $vendorId = (string) $vendor['id'];
        $support = $this->loginAs('payout-support@shop.test', 'support');
        $customer = $this->customerAccount('payout-customer@shop.test');

        $this->assertSame(403, $this->call('GET', '/api/admin/vendors/' . $vendorId . '/ledger', [], $support)['status']);
        $this->assertSame(403, $this->call('GET', '/api/admin/vendors/' . $vendorId . '/ledger', [], $customer['headers'])['status']);
        $this->assertSame(404, $this->call('GET', '/api/admin/vendors/missing/ledger', [], $admin)['status']);
        $this->assertSame(404, $this->call('GET', '/api/account/vendor/ledger', [], $customer['headers'])['status']);

        $this->assertSame(422, $this->call('POST', '/api/admin/vendors/' . $vendorId . '/payouts', [], $admin + ['Idempotency-Key' => 'payout-invalid-1'])['status']);
        $this->assertSame(422, $this->call('POST', '/api/admin/vendors/' . $vendorId . '/payouts', ['period_start' => '2020-01-01', 'period_end' => '2020-01-02'], $admin)['status']);
        $this->assertSame(404, $this->call('POST', '/api/admin/vendor-payouts/missing/mark-paid', [], $admin)['status']);

        // Self-service is available only when the vendor has an owner_customer_id.
        $this->assertSame(200, $this->call('PUT', '/api/admin/vendors/' . $vendorId, ['owner_customer_id' => $customer['id']], $admin)['status']);
        $self = $this->call('GET', '/api/account/vendor/ledger', [], $customer['headers']);
        $this->assertSame(200, $self['status'], (string) json_encode($self['json']));
        $this->assertSame($vendorId, $self['json']['data']['vendor_id']);
        $payouts = $this->call('GET', '/api/account/vendor/payouts', [], $customer['headers']);
        $this->assertSame(200, $payouts['status']);
        $this->assertSame([], $payouts['json']['data']);
    }

    public function testPayoutExportCsvHasBomColumnsAndRowHeader(): void
    {
        $admin = $this->loginAs('payout-export@shop.test', 'owner');
        $vendor = $this->createVendor($admin, 500, 'Export Studio');
        $vendorId = (string) $vendor['id'];
        $product = $this->createProduct($admin, 'export-item', 2000, $vendorId);
        $this->placeAndPay($admin, $product, 1, 'payout-export-place-1');
        $window = ['period_start' => date('Y-m-d', time() - 86400), 'period_end' => date('Y-m-d', time() + 86400)];
        $created = $this->call('POST', '/api/admin/vendors/' . $vendorId . '/payouts', $window, $admin + ['Idempotency-Key' => 'payout-export-batch-1']);
        $this->assertSame(201, $created['status'], (string) json_encode($created['json']));
        $payout = $created['json']['data'];

        $export = $this->callRaw('GET', '/api/admin/vendor-payouts/' . $payout['id'] . '/export.csv', $admin);
        $this->assertSame(200, $export['status']);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $export['body']);
        $this->assertStringStartsWith('text/csv', strtolower($export['headers']['Content-Type'] ?? ''));
        $this->assertSame('1', $export['headers']['X-Export-Rows'] ?? null);
        $this->assertStringContainsString('attachment; filename="vendor-payout-', $export['headers']['Content-Disposition'] ?? '');

        $lines = preg_split('/\r\n/', trim(substr($export['body'], 3))) ?: [];
        $this->assertSame('payout_id,vendor,amount_minor,currency,period_start,period_end,status', $lines[0]);
        $this->assertCount(2, array_values(array_filter($lines)));
        $this->assertStringContainsString((string) $payout['id'], $lines[1]);
        $this->assertStringContainsString('Export Studio', $lines[1]);
        $this->assertStringContainsString('1900', $lines[1]);
        $this->assertStringContainsString('VND', $lines[1]);
        $this->assertStringContainsString('pending', $lines[1]);
    }

    public function testPayoutExportUnknownPayoutIs404AndRequiresStaffRole(): void
    {
        $admin = $this->loginAs('payout-export2@shop.test', 'owner');
        $support = $this->loginAs('payout-export-support@shop.test', 'support');

        $missing = $this->callRaw('GET', '/api/admin/vendor-payouts/missing/export.csv', $admin);
        $this->assertSame(404, $missing['status']);
        $this->assertSame(403, $this->callRaw('GET', '/api/admin/vendor-payouts/missing/export.csv', $support)['status']);
        $this->assertSame(401, $this->callRaw('GET', '/api/admin/vendor-payouts/missing/export.csv')['status']);
    }
    public function testBankCsvReconciliationMarksFailedPaidAndReturned(): void
    {
        $admin = $this->loginAs('payout-reconcile@shop.test', 'owner');
        $vendor = $this->createVendor($admin, 500, 'Reconcile Studio');
        $vendorId = (string) $vendor['id'];
        $product = $this->createProduct($admin, 'reconcile-item', 2000, $vendorId);
        $this->placeAndPay($admin, $product, 1, 'payout-reconcile-place-1');
        $window = ['period_start' => date('Y-m-d', time() - 86400), 'period_end' => date('Y-m-d', time() + 86400)];

        $first = $this->call('POST', '/api/admin/vendors/' . $vendorId . '/payouts', $window, $admin + ['Idempotency-Key' => 'payout-reconcile-1']);
        $this->assertSame(201, $first['status']);
        $payout = $first['json']['data'];
        $csv = "payout_id,status,amount_minor,currency,reference,reason\n" . $payout['id'] . ",failed,1900,VND,BANK-X,account closed\n";
        $failed = $this->call('POST', '/api/admin/vendor-payouts/reconcile', ['csv' => $csv], $admin + ['Idempotency-Key' => 'reconcile-failed-1']);
        $this->assertSame(200, $failed['status'], (string) json_encode($failed['json']));
        $this->assertSame(1, $failed['json']['data']['failed']);
        $payoutRows = $this->call('GET', '/api/admin/vendors/' . $vendorId . '/payouts', [], $admin)['json']['data'];
        $failedState = null;
        foreach ($payoutRows as $row) if (($row['id'] ?? null) === $payout['id']) $failedState = $row['state'] ?? null;
        $this->assertSame('failed', $failedState);
        $ledger = $this->call('GET', '/api/admin/vendors/' . $vendorId . '/ledger', [], $admin);
        $this->assertSame(1900, $ledger['json']['data']['totals']['available']);

        $second = $this->call('POST', '/api/admin/vendors/' . $vendorId . '/payouts', $window, $admin + ['Idempotency-Key' => 'payout-reconcile-2']);
        $this->assertSame(201, $second['status']);
        $paidPayout = $second['json']['data'];
        $paidCsv = "payout_id,status,amount_minor,currency,reference\n" . $paidPayout['id'] . ",settled,1900,VND,BANK-Y\n";
        $paid = $this->call('POST', '/api/admin/vendor-payouts/reconcile', ['csv' => $paidCsv], $admin + ['Idempotency-Key' => 'reconcile-paid-1']);
        $this->assertSame(200, $paid['status'], (string) json_encode($paid['json']));
        $this->assertSame(1, $paid['json']['data']['paid']);
        $ledger = $this->call('GET', '/api/admin/vendors/' . $vendorId . '/ledger', [], $admin);
        $this->assertSame(1900, $ledger['json']['data']['totals']['paid']);

        $returnedCsv = "payout_id,status,amount_minor,currency,reference,reason\n" . $paidPayout['id'] . ",returned,1900,VND,BANK-Z,bank return\n";
        $returned = $this->call('POST', '/api/admin/vendor-payouts/reconcile', ['csv' => $returnedCsv], $admin + ['Idempotency-Key' => 'reconcile-returned-1']);
        $this->assertSame(200, $returned['status'], (string) json_encode($returned['json']));
        $this->assertSame(1, $returned['json']['data']['returned']);
        $ledger = $this->call('GET', '/api/admin/vendors/' . $vendorId . '/ledger', [], $admin);
        $this->assertSame(1900, $ledger['json']['data']['totals']['available']);

        $unknown = "payout_id,status\nmissing-payout,paid\n";
        $error = $this->call('POST', '/api/admin/vendor-payouts/reconcile', ['csv' => $unknown], $admin + ['Idempotency-Key' => 'reconcile-error-1']);
        $this->assertSame(200, $error['status']);
        $this->assertSame(1, $error['json']['data']['errors']);
        $this->assertFalse($error['json']['data']['ok']);
    }
}