<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure;

use App\Modules\Payment\Contracts\PaymentTransport;
use App\Modules\Payment\Domain\PaymentOperation;
use App\Modules\Payment\Domain\PaymentProviderConfig;

final class AuthorizeNetPaymentProvider extends ConfiguredPaymentProvider
{
    public function __construct(PaymentProviderConfig $config, PaymentTransport $transport) { parent::__construct($config, $transport); }
    protected function headers(PaymentOperation $operation): array { return ['X-API-Key' => $this->config->credential('api_key'), 'Content-Type' => 'application/json', 'Idempotency-Key' => $operation->idempotencyKey]; }
}
