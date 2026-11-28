<?php

declare(strict_types=1);

namespace App\Extensions\Siro\ShopperAssistant;

use App\Modules\Ai\Application\AssistantService;
use App\Modules\Customer\Contracts\CustomerReader;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Repositories\UserRepository;
use App\Support\Arr;
use App\Support\CommerceScope;
use Siro\Core\Container;

/**
 * Storefront assistant context builder. Public product facts are sent to the
 * model for everyone. Order facts are included only after the request proves
 * ownership: a signed-in customer for their own order, or a guest with the
 * order's access token. Failed verification silently omits the order so the
 * assistant never reveals whether an order exists.
 */
final class ShopperAssistantService
{
    private const MAX_ID = 64;
    private const MAX_SLUG = 160;
    private const MAX_TOKEN = 256;
    private const MAX_LINES = 20;
    private const MAX_LINE_NAME = 160;

    public function __construct(
        private readonly ?AssistantService $assistant = null,
        private readonly ?ProductRepository $products = null,
        private readonly ?OrderRepository $orders = null,
        private readonly ?UserRepository $users = null,
        private readonly ?CustomerReader $customers = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $user
     * @return array<string, mixed>
     */
    public function ask(CommerceScope $scope, array $data, ?array $user = null): array
    {
        $question = isset($data['question']) && is_string($data['question']) ? trim($data['question']) : '';
        $facts = ['surface' => 'shopper', 'locale' => $scope->locale, 'currency' => $scope->currency];
        $sources = [];

        $productId = $this->clean($data['product_id'] ?? null, self::MAX_ID);
        $slug = $this->clean($data['product_slug'] ?? null, self::MAX_SLUG);
        $product = null;
        $repository = $this->products ?? new ProductRepository();
        if ($productId !== null) {
            $product = $repository->findInScope($productId, $scope);
        } elseif ($slug !== null) {
            $product = $repository->findBySlug($slug, $scope);
        }
        if ($product !== null) {
            $facts['product'] = [
                'id' => $this->text($product['id'] ?? null),
                'name' => $this->text($product['name'] ?? null),
                'slug' => $this->text($product['slug'] ?? null),
                'description' => mb_substr($this->text($product['description'] ?? null), 0, 1200),
                'status' => $this->text($product['status'] ?? null),
                'type' => $this->text($product['type'] ?? null),
                'is_preorder' => $this->flag($product['is_preorder'] ?? null),
            ];
            $productSlug = $this->text($product['slug'] ?? null);
            if ($productSlug !== '') {
                $name = $this->text($product['name'] ?? null);
                $sources[] = ['label' => $name !== '' ? $name : $productId, 'url' => '/product/' . rawurlencode($productSlug)];
            }
        }

        $order = $this->orderFacts($scope, $data, $user);
        if ($order !== null) {
            $facts['order'] = $order;
        }

        if ($sources !== []) {
            $facts['sources'] = $sources;
        }

        $assistant = $this->assistant ?? Container::getInstance()->make(AssistantService::class);
        if (!$assistant instanceof AssistantService) {
            throw new \RuntimeException('Assistant service is unavailable');
        }

        return $assistant->ask($scope, 'shopper', $question, $facts, $this->actorId($user));
    }

    /**
     * Resolve safe order facts only when ownership is proven. A missing,
     * invalid or cross-scope order/token is indistinguishable from no order
     * context at all.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $user
     * @return array<string, mixed>|null
     */
    private function orderFacts(CommerceScope $scope, array $data, ?array $user): ?array
    {
        $orderId = $this->clean($data['order_id'] ?? null, self::MAX_ID);
        if ($orderId === null) {
            return null;
        }

        $orders = $this->orders ?? new OrderRepository();
        $order = $orders->findInScope($orderId, $scope);
        if ($order === null) {
            return null;
        }

        if ($user !== null) {
            if (!$this->userOwnsOrder($order, $user)) {
                return null;
            }
        } else {
            $token = $this->clean($data['order_token'] ?? null, self::MAX_TOKEN);
            if ($token === null || !$orders->guestTokenIsValid($orderId, $token, $scope)) {
                return null;
            }
        }

        return $this->safeOrderFacts($order, $orders->lines($orderId));
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $user
     */
    private function userOwnsOrder(array $order, array $user): bool
    {
        $userId = isset($user['id']) && is_numeric($user['id']) ? (int) $user['id'] : 0;
        $customerId = Arr::str($order, 'customer_id');
        if ($userId <= 0 || $customerId === '') {
            return false;
        }

        $email = isset($user['email']) && is_string($user['email']) ? strtolower(trim($user['email'])) : '';
        if ($email === '') {
            $row = ($this->users ?? new UserRepository())->find($userId);
            $email = is_array($row) && is_string($row['email'] ?? null) ? strtolower(trim($row['email'])) : '';
        }

        $identities = [(string) $userId];
        if ($email !== '') {
            $customer = $this->customerReader()?->findByEmail($email);
            $profileId = is_array($customer) ? Arr::str($customer, 'id') : '';
            if ($profileId !== '') {
                $identities[] = $profileId;
            }
        }

        return in_array($customerId, $identities, true);
    }

    private function customerReader(): ?CustomerReader
    {
        if ($this->customers !== null) {
            return $this->customers;
        }
        try {
            $resolved = Container::getInstance()->make(CustomerReader::class);
        } catch (\Throwable) {
            return null;
        }

        return $resolved instanceof CustomerReader ? $resolved : null;
    }

    /**
     * Only fields a shopper can safely see in their own order. No payment,
     * address, email, customer id, internal token or idempotency data.
     *
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private function safeOrderFacts(array $order, array $lines): array
    {
        $items = [];
        foreach ($lines as $line) {
            if (count($items) >= self::MAX_LINES) {
                break;
            }
            $name = $this->text($line['name'] ?? null);
            if ($name === '') {
                continue;
            }
            $items[] = [
                'name' => mb_substr($name, 0, self::MAX_LINE_NAME),
                'qty' => max(0, Arr::int($line, 'qty')),
            ];
        }

        $totalMinor = max(0, Arr::int($order, 'grand_total_minor'));
        $facts = [
            'number' => $this->text($order['number'] ?? null),
            'state' => $this->text($order['state'] ?? null),
            'currency' => $this->text($order['currency'] ?? null),
            'total_minor' => $totalMinor,
            // Alias used by the staff context; keep the same safe money field
            // so assistants do not have to interpret two schemas.
            'grand_total_minor' => $totalMinor,
            'created_at' => Arr::dt($order, 'created_at'),
        ];
        if ($items !== []) {
            $facts['items'] = $items;
        }

        return $facts;
    }

    /** @param array<string, mixed>|null $user */
    private function actorId(?array $user): string
    {
        $userId = is_array($user) && isset($user['id']) && is_numeric($user['id']) ? (int) $user['id'] : 0;

        return $userId > 0 ? 'customer-' . $userId : 'guest';
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function flag(mixed $value): bool
    {
        return $value === true || $value === 1;
    }

    private function clean(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' && strlen($value) <= $max ? $value : null;
    }
}
