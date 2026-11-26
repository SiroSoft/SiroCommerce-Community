<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Modules\Platform\Infrastructure\CustomerPrivacyService;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;
final class PrivacyController extends Controller
{
    public function __construct(private readonly CustomerPrivacyService $privacy) {}
    public function export(Request $request): Response { return $this->call($request, fn (string $id, string $key): array => $this->privacy->export($id, $key), 'Customer export'); }
    public function anonymize(Request $request): Response { return $this->call($request, fn (string $id, string $key): array => $this->privacy->anonymize($id, $key), 'Customer anonymized'); }
    public function legalHold(Request $request): Response
    {
        $customerId = Arr::requiredParam($request, 'id'); $body = $request->body(); $held = $body['held'] ?? null;
        if (!is_bool($held)) return $this->error('held must be boolean', 422);
        try { return $this->success($this->privacy->setLegalHold($customerId, $held, $this->key($request)), 'Legal hold updated'); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }
    public function consent(Request $request): Response
    {
        $user = $request->user(); $email = is_array($user) && is_string($user['email'] ?? null) ? $user['email'] : '';
        if ($email === '') return $this->error('Not found', 404);
        $data = $request->validate(['purpose' => 'required|string', 'version' => 'required|string', 'source' => 'required|string', 'granted' => 'required|boolean']);
        $customer = $this->customer($email); if ($customer === null) return $this->error('Not found', 404);
        try { return $this->created($this->privacy->recordConsent(Arr::requiredStr($customer, 'id'), $data), 'Consent recorded'); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }
    private function call(Request $request, callable $operation, string $message): Response
    {
        $user = $request->user(); $email = is_array($user) && is_string($user['email'] ?? null) ? $user['email'] : '';
        if ($email === '') return $this->error('Not found', 404); $customer = $this->customer($email);
        if ($customer === null) return $this->error('Not found', 404);
        try { return $this->success($operation(Arr::requiredStr($customer, 'id'), $this->key($request)), $message); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }
    /** @return array<string,mixed>|null */ private function customer(string $email): ?array { $row = \App\Models\Customer::query()->where('email', $email)->first(); return $row === null ? null : Arr::row($row); }
    private function key(Request $request): string { $key = $request->header('Idempotency-Key', ''); return is_string($key) ? $key : ''; }
}
