<?php

declare(strict_types=1);

namespace App\Modules\Ai\Infrastructure;

use App\Modules\Ai\Contracts\AiSuggestionGateway;
use App\Modules\Ai\Contracts\AiSuggestionRequest;
use App\Modules\Ai\Contracts\AiSuggestionResult;
use Siro\Core\Env;
use Siro\Core\Http;
use Siro\Core\HttpResponse;

/**
 * Native Ollama chat gateway (spec LLM_GATEWAY_SPEC, provider `ollama`).
 * No API key. Never throws for provider errors.
 */
final class OllamaGateway implements AiSuggestionGateway
{
    private readonly mixed $request;

    public function __construct(?\Closure $request = null) { $this->request = $request; }

    public function suggest(AiSuggestionRequest $request): AiSuggestionResult
    {
        $base = LlmPrompt::checkBaseUrl((string) Env::get('AI_OLLAMA_URL', (string) Env::get('AI_BASE_URL', '')));
        $model = trim((string) Env::get('AI_OLLAMA_MODEL', (string) Env::get('AI_MODEL', '')));
        if ($base === null || $base === '' || $model === '') {
            return new AiSuggestionResult('provider_unavailable', reason: 'Ollama provider is not configured.');
        }
        [$userContent] = LlmPrompt::userContent($request);
        $payload = [
            'model' => $model,
            'stream' => false,
            'messages' => [
                ['role' => 'system', 'content' => LlmPrompt::system()],
                ['role' => 'user', 'content' => $userContent],
            ],
            'options' => ['temperature' => 0.2, 'num_predict' => max(1, (int) Env::get('AI_MAX_OUTPUT_TOKENS', '512'))],
        ];
        try {
            Http::timeout(max(1, (int) Env::get('AI_TIMEOUT_SECONDS', '20')));
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
            $headers = ['Content-Type' => 'application/json'];
            $response = $this->request === null
                ? Http::post($base . '/api/chat', $body, $headers)
                : ($this->request)($base . '/api/chat', $body, $headers);
            if (!$response instanceof HttpResponse || $response->status() >= 400) {
                return new AiSuggestionResult('provider_unavailable', reason: 'Ollama request failed.');
            }
            $decoded = $response->json();
            $content = is_array($decoded['message'] ?? null) && isset($decoded['message']['content']) && is_string($decoded['message']['content'])
                ? $decoded['message']['content']
                : '';
            if ($content === '') return new AiSuggestionResult('provider_unavailable', reason: 'Ollama returned no content.');
            [$suggestion] = LlmPrompt::suggestionFromText($content);
            return new AiSuggestionResult('available', $suggestion, [
                'provider' => 'ollama',
                'model' => $model,
                'prompt_tokens' => (int) ($decoded['prompt_eval_count'] ?? 0),
                'completion_tokens' => (int) ($decoded['eval_count'] ?? 0),
            ]);
        } catch (\Throwable) {
            return new AiSuggestionResult('provider_unavailable', reason: 'Ollama request failed.');
        }
    }
}
