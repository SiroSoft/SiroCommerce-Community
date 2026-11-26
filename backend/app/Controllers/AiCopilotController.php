<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Ai\Application\AiCopilotService;
use App\Support\Arr;
use App\Support\CommerceScope;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Merchant copilot review queue (spec LLM_GATEWAY_SPEC §copilots). Generates
 * drafts into a queue; approval is an explicit human action.
 */
final class AiCopilotController extends Controller
{
    public function __construct(private readonly AiCopilotService $copilots) {}

    public function enqueue(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
            $body = $request->validate(['task' => 'required|string', 'input' => 'required|array', 'product_id' => 'nullable|string', 'max_cost_minor' => 'nullable|integer']);
            /** @var array<string, mixed> $input */
            $input = is_array($body['input'] ?? null) ? $body['input'] : [];
            $user = $request->user();
            $actor = is_array($user) && is_scalar($user['id'] ?? null) ? (string) $user['id'] : 'unknown';
            $row = $this->copilots->enqueue(
                Arr::requiredStr($body, 'task'),
                $input,
                $scope,
                $actor,
                Arr::str($body, 'product_id') !== '' ? Arr::str($body, 'product_id') : null,
                Arr::int($body, 'max_cost_minor', 0),
            );
            return $this->created($row, 'Copilot suggestion queued for review');
        } catch (\InvalidArgumentException | \App\Support\CommerceScopeException $e) {
            return $this->error($e->getMessage(), 422, [], 'AI_COPILOT_DENIED');
        }
    }

    public function index(Request $request): Response
    {
        $state = $request->queryString('state', '');
        $productId = $request->queryString('product_id', '');
        return $this->success($this->copilots->list($state !== '' ? $state : null, $productId !== '' ? $productId : null), 'Copilot review queue');
    }

    public function approve(Request $request): Response
    {
        $row = $this->copilots->approve(Arr::requiredParam($request, 'id'), $this->actor($request));
        return $row === null ? $this->error('Suggestion not found or already reviewed', 404) : $this->success($row, 'Suggestion approved');
    }

    public function reject(Request $request): Response
    {
        $row = $this->copilots->reject(Arr::requiredParam($request, 'id'), $this->actor($request));
        return $row === null ? $this->error('Suggestion not found or already reviewed', 404) : $this->success($row, 'Suggestion rejected');
    }

    private function actor(Request $request): string
    {
        $user = $request->user();
        return is_array($user) && is_scalar($user['id'] ?? null) ? (string) $user['id'] : 'unknown';
    }
}
