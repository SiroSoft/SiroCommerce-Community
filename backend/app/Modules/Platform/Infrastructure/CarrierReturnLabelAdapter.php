<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;
use App\Modules\Fulfillment\Contracts\ReturnLabelProvider;
use App\Modules\Shipping\Contracts\Carrier;
use Siro\Core\Env;
final class CarrierReturnLabelAdapter implements ReturnLabelProvider
{
    public function __construct(private readonly Carrier $carrier) {}
    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function create(array $payload, string $idempotencyKey): array
    {
        if ((string) Env::get('APP_ENV', 'local') === 'production' && $this->carrier instanceof DefaultCarrier) throw new \RuntimeException('Return-label provider is not configured for production');
        $result = $this->carrier->createReturnLabel(['direction' => 'return'] + $payload, $idempotencyKey);
        if (is_string($result['provider_reference'] ?? null) && !isset($result['provider_ref'])) $result['provider_ref'] = $result['provider_reference'];
        if (is_string($result['tracking_number'] ?? null) && !isset($result['tracking'])) $result['tracking'] = $result['tracking_number'];
        if (!is_string($result['provider_ref'] ?? null) || trim($result['provider_ref']) === '') throw new \RuntimeException('Carrier did not return a return-label reference');
        return $result;
    }
}
