<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Vendor\Application\VendorService;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Public vendor profile. Unknown vendors are 404. Suspended vendors resolve to
 * a profile with `suspended: true` and an empty product listing; the storefront
 * renders the notice instead of hiding the ownership trail.
 */
final class VendorController extends Controller
{
    public function __construct(private readonly VendorService $vendors)
    {
    }

    public function show(Request $request): Response
    {
        $slug = (string) \App\Support\Arr::requiredParam($request, 'slug');
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException) {
            $scope = new CommerceScope('default', 'default', 'en', 'USD');
        }
        $vendor = $this->vendors->publicShow($slug, $scope);
        if ($vendor === null) return $this->error('Vendor not found', 404);
        return $this->success($vendor, 'Vendor profile');
    }
}
