<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Launch\Application\ProviderProbeService;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/** Read-only provider reachability probes for admin/owner operators. Never returns secrets. */
final class ProviderVerifyController extends Controller
{
    public function __construct(private readonly ProviderProbeService $probes)
    {
    }

    public function verify(Request $request): Response
    {
        $result = $this->probes->verify();
        return $this->success($result, $result['certified'] ? 'Providers verified' : 'Provider verification failed');
    }
}
