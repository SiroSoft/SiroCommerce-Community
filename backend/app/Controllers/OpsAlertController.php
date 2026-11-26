<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Smart\Application\OpsAlertService;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Ops anomaly alerts admin (P4 ops intelligence). Read-only list plus ack;
 * detection runs on the hourly smart:aggregate schedule.
 */
final class OpsAlertController extends Controller
{
    public function index(Request $request): Response
    {
        $state = $request->queryString('state', '');
        return $this->success((new OpsAlertService())->list($state !== '' ? $state : null), 'Ops alerts');
    }

    public function ack(Request $request): Response
    {
        $kind = Arr::requiredParam($request, 'kind');
        if (!is_string($kind) || $kind === '') return $this->error('Invalid alert', 422);
        return (new OpsAlertService())->ack($kind)
            ? $this->success(null, 'Alert acknowledged')
            : $this->error('Alert not found', 404);
    }
}
