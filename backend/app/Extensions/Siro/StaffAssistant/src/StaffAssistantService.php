<?php

declare(strict_types=1);

namespace App\Extensions\Siro\StaffAssistant;

use App\Models\Customer;
use App\Modules\Ai\Application\AssistantService;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Support\CommerceScope;
use Siro\Core\Container;

/**
 * Read-only staff assistant context builder.
 *
 * The model only receives a small, explicit whitelist of order/product/
 * customer facts, gated by StaffAssistantPolicy. Payment, token and password
 * fields are never included, and customer PII is only exposed to owners. No
 * staff action is executed by this service.
 */
final class StaffAssistantService
{
    private const MAX_ID = 64;

    public function __construct(
        private readonly ?AssistantService $assistant = null,
        private readonly ?OrderRepository $orders = null,
        private readonly ?ProductRepository $products = null,
        private readonly ?StaffAssistantPolicy $policy = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $user Authenticated principal from the request.
     * @return array<string, mixed>
     */
    public function ask(CommerceScope $scope, array $data, string $actorId, ?array $user = null): array
    {
        $question = isset($data['question']) && is_string($data['question']) ? trim($data['question']) : '';
        $facts = ['surface' => 'staff'];
        $sources = [];
        $policy = $this->policy ?? new StaffAssistantPolicy();

        $orderId = $this->id($data['order_id'] ?? null);
        if ($orderId !== null) {
            $policy->assertAllowed(StaffAssistantPolicy::SCOPE_ORDER, $user);
            $order = ($this->orders ?? new OrderRepository())->findInScope($orderId, $scope);
            if ($order === null) {
                throw new \InvalidArgumentException('Order was not found in this scope');
            }
            $facts['order'] = [
                'id' => $this->text($order['id'] ?? null),
                'number' => $this->text($order['number'] ?? null),
                'state' => $this->text($order['state'] ?? null),
                'currency' => $this->text($order['currency'] ?? null),
                'minor_unit' => $this->integer($order['minor_unit'] ?? null),
                'grand_total_minor' => $this->integer($order['grand_total_minor'] ?? null),
                'created_at' => $this->text($order['created_at'] ?? null),
            ];
            $orderNumber = $this->text($order['number'] ?? null);
            $sources[] = ['label' => 'Order ' . ($orderNumber !== '' ? $orderNumber : $orderId), 'url' => '/orders/' . rawurlencode($orderId)];
        }

        $productId = $this->id($data['product_id'] ?? null);
        if ($productId !== null) {
            $policy->assertAllowed(StaffAssistantPolicy::SCOPE_PRODUCT, $user);
            $product = ($this->products ?? new ProductRepository())->findInScope($productId, $scope);
            if ($product === null) {
                throw new \InvalidArgumentException('Product was not found in this scope');
            }
            $facts['product'] = $this->productFacts($product);
            $slug = $this->text($product['slug'] ?? null);
            if ($slug !== '') {
                $name = $this->text($product['name'] ?? null);
                $sources[] = ['label' => $name !== '' ? $name : $productId, 'url' => '/product/' . rawurlencode($slug)];
            }
        }

        $customerId = $this->id($data['customer_id'] ?? null);
        if ($customerId !== null) {
            $policy->assertAllowed(StaffAssistantPolicy::SCOPE_CUSTOMER, $user);
            $customer = Customer::find($customerId);
            if ($customer === null) {
                throw new \InvalidArgumentException('Customer was not found');
            }
            $facts['customer'] = $this->customerFacts($customer->toArray(), $policy->mayExposeCustomerPii($user));
        }

        if ($sources !== []) {
            $facts['sources'] = $sources;
        }

        $assistant = $this->assistant ?? Container::getInstance()->make(AssistantService::class);
        if (!$assistant instanceof AssistantService) {
            throw new \RuntimeException('Assistant service is unavailable');
        }

        return $assistant->ask($scope, 'staff', $question, $facts, $actorId);
    }

    /**
     * @param array<string, mixed> $customer
     * @return array<string, mixed>
     */
    private function customerFacts(array $customer, bool $exposePii): array
    {
        $name = $this->text($customer['name'] ?? null);
        $facts = [
            'id' => $this->text($customer['id'] ?? null),
            'created_at' => $this->text($customer['created_at'] ?? null),
        ];
        if ($exposePii) {
            $facts['name'] = $name;
            $email = $this->text($customer['email'] ?? null);
            if ($email !== '') {
                $facts['email'] = $email;
            }
        } elseif ($name !== '') {
            $facts['name'] = mb_substr($name, 0, 1) . '***';
        }

        return $facts;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function productFacts(array $product): array
    {
        return [
            'id' => $this->text($product['id'] ?? null),
            'name' => $this->text($product['name'] ?? null),
            'slug' => $this->text($product['slug'] ?? null),
            'status' => $this->text($product['status'] ?? null),
            'publish_state' => $this->text($product['publish_state'] ?? null),
            'type' => $this->text($product['type'] ?? null),
            'is_preorder' => $this->flag($product['is_preorder'] ?? null),
        ];
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function integer(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    private function flag(mixed $value): bool
    {
        return $value === true || $value === 1;
    }

    private function id(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > self::MAX_ID) {
            return null;
        }

        return $value;
    }
}
