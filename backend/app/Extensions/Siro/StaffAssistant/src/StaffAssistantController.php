<?php

declare(strict_types=1);

namespace App\Extensions\Siro\StaffAssistant;

use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\AuthorizationException;
use Siro\Core\Controller;
use Siro\Core\Env;
use Siro\Core\Request;
use Siro\Core\Response;

/** Thin HTTP layer for the read-only staff assistant. */
final class StaffAssistantController extends Controller
{
    public function __construct(private readonly StaffAssistantService $service = new StaffAssistantService())
    {
    }

    public function status(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        $provider = strtolower(trim((string) Env::get('AI_PROVIDER', 'unavailable')));
        $configured = in_array($provider, ['openai', 'ollama'], true);

        return $this->success([
            'configured' => $configured,
            'provider' => $configured ? $provider : 'unavailable',
            'read_only' => true,
            'scope' => $scope->storeId . ' / ' . $scope->channelId,
            'stats' => [
                ['label' => 'Provider', 'value' => $configured ? $provider : 'not configured'],
                ['label' => 'Mode', 'value' => 'read-only'],
                ['label' => 'Actions', 'value' => 'proposal only'],
            ],
        ], 'Staff assistant status');
    }

    public function ask(Request $request): Response
    {
        $data = $request->validate([
            'question' => 'required|string|min:3|max:1000',
            'order_id' => 'nullable|string|max:64',
            'product_id' => 'nullable|string|max:64',
            'customer_id' => 'nullable|string|max:64',
        ]);
        try {
            $scope = CommerceScope::fromRequest($request);
            $user = $request->user();
            $principal = is_array($user) ? $user : null;
            $actor = $principal !== null && is_scalar($principal['id'] ?? null) ? (string) $principal['id'] : 'staff';
            $result = $this->service->ask($scope, $data, $actor, $principal);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        } catch (AuthorizationException $exception) {
            return $this->error($exception->getMessage(), 403, ['context' => ['Forbidden.']], 'FORBIDDEN');
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 409);
        }

        return $this->success($result, 'Staff assistant response');
    }
}
