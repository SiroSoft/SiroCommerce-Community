<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Platform\Application\FeatureFlagService;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Read-only edition feature flags for the admin console.
 *
 * The payload exposes only the normalized edition and boolean flags. It never
 * echoes PRO_FEATURES, license keys, signing secrets or other configuration.
 */
final class FeatureFlagController extends Controller
{
    public function __construct(private readonly ?FeatureFlagService $features = null) {}

    public function index(Request $request): Response
    {
        $service = $this->features ?? new FeatureFlagService();

        return $this->success($service->all(), 'Feature flags');
    }
}
