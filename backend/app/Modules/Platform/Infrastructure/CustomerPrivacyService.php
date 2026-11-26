<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;

use App\Models\Address;
use App\Models\ConsentAuditEvent;
use App\Models\Customer;
use App\Models\CustomerNotificationPreference;
use App\Models\GuestOrderAccess;
use App\Models\Order;
use App\Models\PrivacyRequest;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Audit;
use Siro\Core\Database;

/** Customer privacy operations never delete or rewrite financial evidence. */
final class CustomerPrivacyService
{
    /** @return array<string,mixed> */
    public function export(string $customerId, string $key): array
    {
        return $this->request($customerId, 'export', $key, function (Customer $customer): array {
            $id = Arr::requiredStr(Arr::row($customer), 'id');
            $orders = [];
            foreach (Order::query()->where('customer_id', $id)->get() as $order) {
                $orders[] = array_intersect_key(Arr::row($order), array_flip([
                    'id', 'number', 'state', 'currency', 'subtotal_minor', 'shipping_minor',
                    'tax_minor', 'grand_total_minor', 'created_at', 'updated_at',
                ]));
            }
            $addresses = [];
            foreach (Address::query()->where('customer_id', $id)->get() as $address) {
                $addresses[] = array_intersect_key(Arr::row($address), array_flip([
                    'id', 'label', 'line1', 'city', 'phone', 'created_at', 'updated_at',
                ]));
            }
            $preference = CustomerNotificationPreference::query()->where('customer_id', $id)->first();
            $consents = [];
            foreach (ConsentAuditEvent::query()->where('customer_id', $id)->orderBy('occurred_at', 'asc')->get() as $consent) {
                $consents[] = array_intersect_key(Arr::row($consent), array_flip(['purpose', 'granted', 'version', 'source', 'occurred_at']));
            }
            return [
                'customer' => array_intersect_key(Arr::row($customer), array_flip(['id', 'name', 'email', 'phone', 'created_at'])),
                'orders' => $orders,
                'addresses' => $addresses,
                'notification_preferences' => $preference === null ? [] : array_intersect_key(Arr::row($preference), array_flip(['transactional_email', 'marketing_email', 'updated_at'])),
                'consents' => $consents,
            ];
        });
    }

    /** @return array<string,mixed> */
    public function anonymize(string $customerId, string $key): array
    {
        return $this->request($customerId, 'anonymize', $key, function (Customer $customer): array {
            $id = Arr::requiredStr(Arr::row($customer), 'id');
            $holds = PrivacyRequest::query()->where('customer_id', $id)->where('type', 'legal_hold')->get();
            foreach ($holds as $hold) if (Arr::int(Arr::row($hold), 'legal_hold') === 1) {
                throw new \RuntimeException('Customer data is subject to legal hold');
            }
            $anonymous = 'deleted-' . strtolower(substr(hash('sha256', $id), 0, 20)) . '@invalid.local';
            $customer->fill(['email' => $anonymous, 'name' => 'Deleted customer']);
            $customer->save();
            foreach (Order::query()->where('customer_id', $id)->get() as $order) {
                $orderId = Arr::requiredStr(Arr::row($order), 'id');
                $order->setAttribute('guest_email', null); $order->save();
                GuestOrderAccess::query()->where('order_id', $orderId)->update(['revoked_at' => date('Y-m-d H:i:s')]);
            }
            foreach (Address::query()->where('customer_id', $id)->get() as $address) $address->delete();
            $preferences = CustomerNotificationPreference::query()->where('customer_id', $id)->first();
            if ($preferences !== null) { $preferences->fill(['transactional_email' => 0, 'marketing_email' => 0]); $preferences->save(); }
            Audit::log('commerce.customer.anonymized', ['customer_id' => $id]);
            return ['customer_id' => $id, 'state' => 'anonymized', 'financial_records' => 'retained'];
        });
    }

    /** @return array<string,mixed> */
    public function setLegalHold(string $customerId, bool $held, string $key): array
    {
        return $this->request($customerId, 'legal_hold', $key, function (Customer $customer) use ($held): array {
            return ['customer_id' => Arr::requiredStr(Arr::row($customer), 'id'), 'legal_hold' => $held ? 1 : 0, 'state' => $held ? 'held' : 'released'];
        }, $held, $held ? 'held' : 'released');
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed> */
    public function recordConsent(string $customerId, array $data): array
    {
        $purposeValue = $data['purpose'] ?? ''; $versionValue = $data['version'] ?? ''; $sourceValue = $data['source'] ?? '';
        $purpose = is_string($purposeValue) ? trim($purposeValue) : ''; $version = is_string($versionValue) ? trim($versionValue) : '';
        $source = is_string($sourceValue) ? trim($sourceValue) : ''; $granted = $data['granted'] ?? null;
        if ($purpose === '' || $version === '' || !is_bool($granted) || $source === '') throw new \InvalidArgumentException('Invalid consent contract');
        if (!in_array($purpose, ['analytics', 'marketing', 'transactional_email'], true)) throw new \InvalidArgumentException('Unsupported consent purpose');
        if (Customer::find($customerId) === null) throw new \RuntimeException('Customer not found');
        $row = new ConsentAuditEvent(); $row->fill(['id' => Ids::new(), 'customer_id' => $customerId, 'purpose' => $purpose,
            'granted' => $granted ? 1 : 0, 'version' => $version, 'source' => $source, 'occurred_at' => date('Y-m-d H:i:s')]); $row->save();
        return Arr::row($row);
    }

    /** @param callable(Customer):array<string,mixed> $callback
     * @return array<string,mixed> */
    private function request(string $customerId, string $type, string $key, callable $callback, bool $legalHold = false, string $fingerprintSuffix = ''): array
    {
        $key = trim($key); if ($key === '' || strlen($key) > 128) throw new \InvalidArgumentException('Idempotency-Key is required');
        $result = Database::transaction(function () use ($customerId, $type, $key, $callback, $legalHold, $fingerprintSuffix): mixed {
            $fingerprint = hash('sha256', $type . '|' . $customerId . '|' . $fingerprintSuffix);
            $existing = PrivacyRequest::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                $row = Arr::row($existing); if (Arr::str($row, 'fingerprint') !== $fingerprint) throw new \InvalidArgumentException('Idempotency key was reused');
                $result = json_decode(Arr::str($row, 'result_json', '{}'), true); return is_array($result) ? Arr::row($result) : [];
            }
            $customer = $this->lockedCustomer($customerId);
            if (!$customer instanceof Customer) throw new \RuntimeException('Customer not found');
            $result = $callback($customer);
            $request = new PrivacyRequest(); $request->fill(['id' => Ids::new(), 'customer_id' => $customerId, 'type' => $type,
                'state' => 'completed', 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'result_json' => json_encode($result, JSON_THROW_ON_ERROR), 'legal_hold' => $legalHold ? 1 : 0, 'created_at' => date('Y-m-d H:i:s'), 'completed_at' => date('Y-m-d H:i:s')]); $request->save();
            return $result;
        });
        if (!is_array($result)) throw new \RuntimeException('Privacy request returned invalid data');
        return Arr::row($result);
    }

    private function lockedCustomer(string $customerId): mixed
    {
        return Customer::query()->where('id', $customerId)->lockForUpdate()->first();
    }
}
