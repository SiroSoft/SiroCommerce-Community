<?php

declare(strict_types=1);

namespace App\Modules\Ai\Application;

use App\Modules\Ai\Contracts\AiAuditWriter;
use App\Modules\Ai\Contracts\AiCostLedger;
use App\Modules\Ai\Contracts\AiSuggestionGateway;
use App\Modules\Ai\Contracts\AiSuggestionPolicy;
use App\Modules\Ai\Contracts\AiSuggestionRequest;
use App\Modules\Ai\Contracts\AiSuggestionResult;

final class AiSuggestionService
{
    public function __construct(private readonly AiSuggestionGateway $gateway, private readonly AiCostLedger $costs, private readonly AiAuditWriter $audit) {}

    public function suggest(AiSuggestionRequest $request): AiSuggestionResult
    {
        AiSuggestionPolicy::assertReadOnlyTask($request->task);
        $tenant = (string) $request->scope->tenantId;
        if (!AiSuggestionPolicy::allows($request, $this->costs->remainingMinor($tenant))) {
            $result = new AiSuggestionResult('budget_exceeded', reason: 'Tenant AI budget is unavailable for this request.');
            $this->audit->record($tenant, $request->actorId, $request->task, $result->status);
            return $result;
        }
        if (!$this->costs->reserveMinor($tenant, $request->maxCostMinor)) {
            $result = new AiSuggestionResult('budget_exceeded', reason: 'Tenant AI budget is unavailable for this request.');
            $this->audit->record($tenant, $request->actorId, $request->task, $result->status);
            return $result;
        }
        $result = $this->gateway->suggest($request);
        $provider = $result->usage['provider'] ?? null;
        $model = $result->usage['model'] ?? null;
        $this->audit->record($tenant, $request->actorId, $request->task, $result->status, ['usage' => $result->usage]
            + (is_string($provider) ? ['provider' => $provider] : [])
            + (is_string($model) ? ['model' => $model] : []));
        return $result;
    }
}
