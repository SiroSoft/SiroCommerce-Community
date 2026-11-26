<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure;

use App\Modules\Payment\Contracts\PaymentTransport;
use App\Modules\Payment\Domain\PaymentOperation;
use App\Modules\Payment\Domain\PaymentProviderConfig;

final class StripePaymentProvider extends ConfiguredPaymentProvider
{
    public function __construct(PaymentProviderConfig $config, PaymentTransport $transport) { parent::__construct($config, $transport); }
    protected function headers(PaymentOperation $operation): array { return ['Authorization' => 'Bearer ' . $this->config->credential('secret_key'), 'Content-Type' => 'application/json', 'Idempotency-Key' => $operation->idempotencyKey]; }
}
