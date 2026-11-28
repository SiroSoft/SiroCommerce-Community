<?php

declare(strict_types=1);

namespace App\Modules\Ai\Application;

use App\Modules\Ai\Contracts\AiSuggestionRequest;
use App\Modules\Ai\Contracts\AiSuggestionResult;
use App\Support\CommerceScope;
use Siro\Core\Env;

/**
 * Read-only assistant facade shared by the staff and shopper extensions.
 *
 * It never mutates commerce state. Every call goes through AiSuggestionService
 * so the existing prompt redaction, tenant budget and audit ledger apply.
 *
 * Multilingual requests use English as the pivot language: the target answer
 * language comes from the commerce scope, the question is translated to
 * English, grounded through the normal assistant task, and the English answer
 * is translated back. Translation failures fall back to the original question
 * or English answer instead of inventing content.
 */
final class AssistantService
{
    public const SURFACES = ['staff', 'shopper'];
    public const MIN_QUESTION = 3;
    public const MAX_QUESTION = 1000;
    public const MAX_CONTEXT_BYTES = 6000;
    public const MAX_ANSWER_CHARS = 4000;
    public const MAX_ACTOR = 64;
    public const MAX_COST_MINOR = 1_000_000;
    public const TRANSLATE_IN_TASK = 'assistant.translate.in';
    public const TRANSLATE_OUT_TASK = 'assistant.translate.out';

    public function __construct(private readonly AiSuggestionService $suggestions)
    {
    }

    /**
     * @param array<string, mixed> $context Safe facts assembled by the caller.
     * @return array{status: string, answer: string|null, sources: list<array{label: string, url: string}>, read_only: bool, autonomous_mutation: bool, reason: string|null}
     */
    public function ask(
        CommerceScope $scope,
        string $surface,
        string $question,
        array $context,
        string $actorId,
        int $maxCostMinor = 0,
    ): array {
        $surface = strtolower(trim($surface));
        if (!in_array($surface, self::SURFACES, true)) {
            throw new \InvalidArgumentException('Unsupported assistant surface');
        }
        $question = trim($question);
        $length = mb_strlen($question);
        if ($length < self::MIN_QUESTION || $length > self::MAX_QUESTION) {
            throw new \InvalidArgumentException('Question must be between 3 and 1000 characters');
        }
        $question = $this->redactText($question);
        $actorId = trim($actorId);
        $actorId = $actorId !== '' ? substr($actorId, 0, self::MAX_ACTOR) : 'guest';
        $maxCostMinor = max(0, min(self::MAX_COST_MINOR, $maxCostMinor));

        $facts = $this->boundedFacts($context);
        $scope = $this->withTenant($scope);
        $targetLocale = $this->targetLocale($scope->locale);

        $questionForModel = $question;
        $questionTranslated = false;
        if ($targetLocale !== 'en') {
            $englishQuestion = $this->translate($scope, $question, $targetLocale, 'en', self::TRANSLATE_IN_TASK, $actorId);
            if ($englishQuestion !== null) {
                $questionForModel = mb_substr($englishQuestion, 0, self::MAX_QUESTION);
                $questionTranslated = true;
            }
        }

        $result = $this->suggestions->suggest(new AiSuggestionRequest(
            'assistant.' . $surface,
            ['surface' => $surface, 'question' => $questionForModel, 'facts' => $facts, 'output_contract' => ['answer' => 'concise natural-language answer in English']],
            $scope,
            $actorId,
            $maxCostMinor,
        ));

        $answer = $this->answer($result);
        if ($answer !== null && $targetLocale !== 'en' && $questionTranslated) {
            $translatedAnswer = $this->translate($scope, $answer, 'en', $targetLocale, self::TRANSLATE_OUT_TASK, $actorId);
            if ($translatedAnswer !== null) {
                $answer = mb_substr($translatedAnswer, 0, self::MAX_ANSWER_CHARS);
            }
        }

        return [
            'status' => $result->status,
            'answer' => $answer,
            'sources' => $this->sources($facts),
            'read_only' => true,
            'autonomous_mutation' => false,
            'reason' => $this->reason($result),
        ];
    }

    /**
     * Translate auxiliary text through the existing read-only gateway rails.
     * Any provider/budget/failure degrades to null so the caller can fall back.
     */
    private function translate(
        CommerceScope $scope,
        string $text,
        string $from,
        string $to,
        string $task,
        string $actorId,
    ): ?string {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        try {
            $result = $this->suggestions->suggest(new AiSuggestionRequest(
                $task,
                [
                    'text' => mb_substr($text, 0, self::MAX_ANSWER_CHARS),
                    'from' => $from,
                    'to' => $to,
                ],
                $scope,
                $actorId,
                0,
            ));
        } catch (\Throwable) {
            return null;
        }
        if ($result->status !== 'available') {
            return null;
        }
        foreach (['translation', 'answer', 'text', 'message'] as $key) {
            $candidate = $result->suggestion[$key] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * Redact high-confidence PII from free text before translation/model use.
     * Key-based AnalyticsRedactor cannot see values embedded in a question.
     */
    private function redactText(string $text): string
    {
        $patterns = [
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i' => '[redacted-email]',
            '/\b[A-Fa-f0-9]{32,}\b/' => '[redacted-token]',
            '/(?<![\d.,])(?:\+?\d[\d\s().-]{8,}\d)(?![\d.,])/' => '[redacted-phone]',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $redacted = preg_replace($pattern, $replacement, $text);
            if (is_string($redacted)) {
                $text = $redacted;
            }
        }

        return $text;
    }

    private function targetLocale(string $locale): string
    {
        $base = $this->baseLanguage($locale);
        if ($base !== null && in_array($base, $this->supportedLanguages(), true)) {
            return $base;
        }

        return 'en';
    }

    /** @return list<string> */
    private function supportedLanguages(): array
    {
        $configured = trim((string) Env::get('ASSISTANT_LANGUAGES', ''));
        $candidates = $configured !== '' ? explode(',', $configured) : CommerceScope::ALLOWED_LOCALES;
        $languages = ['en'];
        foreach ($candidates as $candidate) {
            $base = $this->baseLanguage($candidate);
            if ($base !== null && !in_array($base, $languages, true)) {
                $languages[] = $base;
            }
        }

        return $languages;
    }

    private function baseLanguage(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }
        $base = explode('-', str_replace('_', '-', $value))[0];

        return preg_match('/^[a-z]{2,8}$/', $base) === 1 ? $base : null;
    }

    private function withTenant(CommerceScope $scope): CommerceScope
    {
        if ($scope->tenantId !== null) {
            return $scope;
        }
        if (trim($scope->storeId) === '') {
            throw new \InvalidArgumentException('Assistant requires a store scope');
        }

        return new CommerceScope(
            $scope->storeId,
            $scope->channelId,
            $scope->locale,
            $scope->currency,
            $scope->storeId,
            $scope->sellerId,
            $scope->siteId,
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function boundedFacts(array $context): array
    {
        try {
            $encoded = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Assistant context is not encodable');
        }
        if (strlen($encoded) > self::MAX_CONTEXT_BYTES) {
            throw new \InvalidArgumentException('Assistant context is too large');
        }

        return $context;
    }

    private function answer(AiSuggestionResult $result): ?string
    {
        if ($result->status !== 'available') {
            return null;
        }
        foreach (['answer', 'text', 'message', 'summary', 'response', 'content'] as $key) {
            $candidate = $result->suggestion[$key] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return mb_substr(trim($candidate), 0, self::MAX_ANSWER_CHARS);
            }
        }

        $fallback = $this->scalarSummary($result->suggestion);

        return $fallback !== '' ? mb_substr($fallback, 0, self::MAX_ANSWER_CHARS) : null;
    }

    /** @param array<mixed, mixed> $suggestion */
    private function scalarSummary(array $suggestion, int $depth = 0): string
    {
        if ($depth > 2) {
            return '';
        }
        $parts = [];
        foreach ($suggestion as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $parts[] = (string) $key . ': ' . trim($value);
            } elseif (is_int($value) || is_float($value)) {
                $parts[] = (string) $key . ': ' . (string) $value;
            } elseif (is_array($value)) {
                $nested = $this->scalarSummary($value, $depth + 1);
                if ($nested !== '') {
                    $parts[] = $nested;
                }
            }
            if (count($parts) >= 8) {
                break;
            }
        }

        return implode('; ', $parts);
    }

    private function reason(AiSuggestionResult $result): ?string
    {
        if ($result->status === 'available') {
            return null;
        }
        $reason = trim((string) ($result->reason ?? ''));

        return $reason !== '' ? mb_substr($reason, 0, 255) : $result->status;
    }

    /**
     * @param array<string, mixed> $facts
     * @return list<array{label: string, url: string}>
     */
    private function sources(array $facts): array
    {
        $raw = $facts['sources'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $sources = [];
        foreach ($raw as $source) {
            if (!is_array($source)) {
                continue;
            }
            $label = $source['label'] ?? null;
            $url = $source['url'] ?? null;
            if (!is_string($label) || trim($label) === '' || !is_string($url)) {
                continue;
            }
            $url = trim($url);
            if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
                if (filter_var($url, FILTER_VALIDATE_URL) === false || !preg_match('#^https://#i', $url)) {
                    continue;
                }
            }
            $sources[] = ['label' => mb_substr(trim($label), 0, 120), 'url' => mb_substr($url, 0, 500)];
            if (count($sources) === 5) {
                break;
            }
        }

        return $sources;
    }
}
