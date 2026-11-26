<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Launch\Application\ProviderStatusService;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/** Provider configuration status for the launch checklist. Never returns secrets. */
final class ProviderStatusController extends Controller
{
    public function __construct(private readonly ProviderStatusService $providers)
    {
    }

    public function status(Request $request): Response
    {
        return $this->success($this->providers->status(), 'Provider status');
    }
}
