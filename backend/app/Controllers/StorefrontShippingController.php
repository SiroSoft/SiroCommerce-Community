<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Shipping\Application\ShippingEstimateService;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use App\Support\PublicErrorMessage;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\ValidationException;

final class StorefrontShippingController extends Controller
{
    public function __construct(private readonly ?ShippingEstimateService $estimates = null) {}

    public function estimate(Request $request): Response
    {
        try {
            /** @var array<string, mixed> $data */
            $data = $request->validate([
                'variant_id' => 'required|string|max:64',
                'qty' => 'nullable|integer|min:1|max:99',
                'destination' => 'required|array',
            ]);
            $scope = CommerceScope::fromRequest($request);
            $destination = is_array($data['destination'] ?? null) ? $data['destination'] : [];
            $result = ($this->estimates ?? new ShippingEstimateService())->estimate(
                (string) ($data['variant_id'] ?? ''),
                is_numeric($data['qty'] ?? null) ? (int) $data['qty'] : 1,
                $destination,
                $scope,
            );
            return $this->success($result, 'Shipping estimate');
        } catch (ValidationException $e) {
            return $e->toResponse();
        } catch (CommerceScopeException $e) {
            return $this->error($e->getMessage(), 400, [], 'SCOPE_ERROR');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->error(PublicErrorMessage::from($e->getMessage(), 'Shipping estimate could not be completed'), 422, [], 'VALIDATION_ERROR');
        }
    }
}
