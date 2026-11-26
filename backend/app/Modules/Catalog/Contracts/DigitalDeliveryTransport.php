<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

interface DigitalDeliveryTransport
{
    /**
     * @param array<string, mixed> $asset
     * @return array{content: string, mime: string, filename: string}
     */
    public function read(array $asset): array;
}
