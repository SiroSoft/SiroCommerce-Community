<?php

declare(strict_types=1);

namespace App\Modules\Ai\Infrastructure;

use App\Modules\Ai\Contracts\AiSuggestionRequest;
use App\Modules\Analytics\Domain\AnalyticsRedactor;

/** Shared prompt + redaction rails for LLM gateways (spec LLM_GATEWAY_SPEC). */
final class LlmPrompt
{
    public const MAX_INPUT_BYTES = 8192;

    public static function system(): string
    {
        return 'You are a read-only merchandising assistant for an online store. '
            . 'Reply with a single JSON object and nothing else. '
            . 'Never invent prices, stock levels, URLs, product names, or order data; '
            . 'only restate or reorganize the input you were given. '
            . 'You cannot place orders, change prices, or take any other action.';
    }

    /** @return array{0: string, 1: bool} redacted user content + whether it was capped */
    public static function userContent(AiSuggestionRequest $request): array
    {
        $safe = AnalyticsRedactor::redact($request->input);
        $json = (string) json_encode(['task' => $request->task, 'input' => $safe], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > self::MAX_INPUT_BYTES) {
            $json = substr($json, 0, self::MAX_INPUT_BYTES);
            return [$json, true];
        }
        return [$json, false];
    }

    /**
     * @return array{0: array<string, mixed>, 1: string} suggestion + raw text
     */
    public static function suggestionFromText(string $content): array
    {
        $text = trim($content);
        if ($text === '') return [[], ''];
        $fenced = preg_replace('/\A```(?:json)?\s*|\s*```\z/', '', $text);
        $candidate = is_string($fenced) ? trim($fenced) : $text;
        $decoded = json_decode($candidate, true);
        if (is_array($decoded)) return [$decoded, mb_substr($text, 0, 4000)];
        return [['text' => mb_substr($text, 0, 4000)], mb_substr($text, 0, 4000)];
    }

    public static function checkBaseUrl(string $base): ?string
    {
        $base = rtrim(trim($base), '/');
        if ($base === '' || filter_var($base, FILTER_VALIDATE_URL) === false) return null;
        if (!in_array(strtolower((string) parse_url($base, PHP_URL_SCHEME)), ['http', 'https'], true)) return null;
        return $base;
    }
}
