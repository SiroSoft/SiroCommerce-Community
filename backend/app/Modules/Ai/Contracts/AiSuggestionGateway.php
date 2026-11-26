<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

interface AiSuggestionGateway
{
    public function suggest(AiSuggestionRequest $request): AiSuggestionResult;
}
