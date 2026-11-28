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
 * OpenAI-compatible chat gateway (spec LLM_GATEWAY_SPEC, provider `openai`).
 * Never throws for provider errors: everything maps to a status result.
 */
final class OpenAiCompatibleGateway implements AiSuggestionGateway
{
    private readonly mixed $request;

    public function __construct(?\Closure $request = null) { $this->request = $request; }

    public function suggest(AiSuggestionRequest $request): AiSuggestionResult
    {
        $base = LlmPrompt::checkBaseUrl((string) Env::get('AI_BASE_URL', ''));
        $model = trim((string) Env::get('AI_MODEL', ''));
        $key = trim((string) Env::get('AI_API_KEY', ''));
        if ($base === null || $base === '' || $model === '' || $key === '') {
            return new AiSuggestionResult('provider_unavailable', reason: 'OpenAI-compatible provider is not configured.');
        }
        [$userContent] = LlmPrompt::userContent($request);
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => LlmPrompt::system()],
                ['role' => 'user', 'content' => $userContent],
            ],
            'temperature' => 0.2,
            'max_tokens' => max(1, (int) Env::get('AI_MAX_OUTPUT_TOKENS', '4096')),
        ];
        try {
            Http::timeout(max(1, (int) Env::get('AI_TIMEOUT_SECONDS', '120')));
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
            $headers = ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key];
            $response = $this->request === null
                ? Http::post($base . '/chat/completions', $body, $headers)
                : ($this->request)($base . '/chat/completions', $body, $headers);
            if (!$response instanceof HttpResponse || $response->status() >= 400) {
                return new AiSuggestionResult('provider_unavailable', reason: 'AI provider request failed.');
            }
            $decoded = $response->json();
            $content = is_array($decoded['choices'] ?? null) && isset($decoded['choices'][0]['message']['content']) && is_string($decoded['choices'][0]['message']['content'])
                ? $decoded['choices'][0]['message']['content']
                : '';
            if ($content === '') return new AiSuggestionResult('provider_unavailable', reason: 'AI provider returned no content.');
            [$suggestion] = LlmPrompt::suggestionFromText($content);
            $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
            return new AiSuggestionResult('available', $suggestion, [
                'provider' => 'openai',
                'model' => $model,
                'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            ]);
        } catch (\Throwable) {
            return new AiSuggestionResult('provider_unavailable', reason: 'AI provider request failed.');
        }
    }
}
