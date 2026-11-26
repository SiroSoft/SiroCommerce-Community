<?php

declare(strict_types=1);

namespace App\Modules\Ai\Infrastructure;

use App\Modules\Ai\Contracts\AiSuggestionGateway;
use App\Modules\Ai\Contracts\AiSuggestionRequest;
use App\Modules\Ai\Contracts\AiSuggestionResult;

final class UnavailableAiSuggestionGateway implements AiSuggestionGateway
{
    public function suggest(AiSuggestionRequest $request): AiSuggestionResult
    {
        return new AiSuggestionResult('provider_unavailable', reason: 'No AI provider is configured; no external model was called.');
    }
}
