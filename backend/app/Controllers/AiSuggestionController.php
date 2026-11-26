<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Ai\Application\AiSuggestionService;
use App\Modules\Ai\Contracts\AiSuggestionRequest;
use App\Support\CommerceScope;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class AiSuggestionController extends Controller
{
    public function __construct(private readonly AiSuggestionService $suggestions) {}

    public function suggest(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
            $body = $request->body();
            $task = is_string($body['task'] ?? null) ? $body['task'] : '';
            $input = [];
            if (is_array($body['input'] ?? null)) foreach ($body['input'] as $key => $value) if (is_string($key)) $input[$key] = $value;
            $budget = is_int($body['max_cost_minor'] ?? null) ? $body['max_cost_minor'] : 0;
            $user = $request->user();
            $actor = is_array($user) && is_scalar($user['id'] ?? null) ? (string) $user['id'] : 'unknown';
            $result = $this->suggestions->suggest(new AiSuggestionRequest($task, $input, $scope, $actor, $budget));
            return $this->success($result->toArray(), 'AI suggestion contract result')->header('Cache-Control', 'private, no-store');
        } catch (\InvalidArgumentException | \App\Support\CommerceScopeException $e) {
            return $this->error($e->getMessage(), 422, [], 'AI_SUGGESTION_DENIED');
        }
    }
}
