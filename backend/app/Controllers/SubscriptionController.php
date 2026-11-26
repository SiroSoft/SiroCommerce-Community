<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Modules\Subscriptions\Application\SubscriptionService;
use App\Resources\SubscriptionResource;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function index(Request $request): Response
    {
        try {
            $actor = $this->actor($request);
            $result = $this->subscriptions->listForCustomer($actor['id'], $this->page($request), $this->perPage($request), $actor['id'], $actor['role']);
            return $this->paginated(array_map(static fn (array $row): array => SubscriptionResource::make($row), $result['data']), $result['meta'], 'Subscriptions');
        } catch (\RuntimeException $e) { return $this->error($e->getMessage(), $e->getMessage() === 'Forbidden' ? 403 : 401); }
    }

    public function show(Request $request): Response
    {
        try {
            $actor = $this->actor($request);
            $subscription = $this->subscriptions->findForCustomer(Arr::requiredParam($request, 'id'), $actor['id'], $actor['role']);
            return $subscription === null ? $this->error('Subscription not found', 404) : $this->success(SubscriptionResource::make($subscription), 'Subscription');
        } catch (\RuntimeException $e) { return $this->error($e->getMessage(), $e->getMessage() === 'Forbidden' ? 403 : 401); }
    }

    public function start(Request $request): Response
    {
        try { $data = $request->validate(['plan_id' => 'required|string']); $actor = $this->actor($request); return $this->success($this->subscriptions->start($actor['id'], Arr::requiredStr($data, 'plan_id'), $this->key($request), $actor['id'], $actor['role']), 'Subscription started'); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), $e->getMessage() === 'Forbidden' ? 403 : 422); }
    }
    public function transition(Request $request): Response
    {
        try { $data = $request->validate(['action' => 'required|string']); $actor = $this->actor($request); return $this->success($this->subscriptions->transition(Arr::requiredParam($request, 'id'), Arr::requiredStr($data, 'action'), $this->key($request), $actor['id'], $actor['role']), 'Subscription updated'); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), $e->getMessage() === 'Forbidden' ? 403 : 422); }
    }
    public function renew(Request $request): Response
    {
        try { $actor = $this->actor($request); return $this->success($this->subscriptions->renew(Arr::requiredParam($request, 'id'), $this->key($request), $actor['id'], $actor['role']), 'Subscription renewal queued'); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), $e->getMessage() === 'Forbidden' ? 403 : 422); }
    }
    /** @return array{id: string, role: string} */
    private function actor(Request $request): array
    { $user = $request->user(); $id = is_array($user) && (is_string($user['id'] ?? null) || is_int($user['id'] ?? null)) ? (string) $user['id'] : ''; $role = is_array($user) && is_string($user['role'] ?? null) ? $user['role'] : ''; if ($id === '') throw new \RuntimeException('Forbidden'); return ['id' => $id, 'role' => $role]; }
    private function key(Request $request): string
    { $key = $request->header('idempotency-key', ''); if (!is_string($key) || $key === '') throw new \InvalidArgumentException('Idempotency-Key is required'); return $key; }
    private function page(Request $request): int { return max(1, $request->queryInt('page', 1)); }
    private function perPage(Request $request): int { return min(100, max(1, $request->queryInt('per_page', 20))); }
}
