<?php
declare(strict_types=1);

namespace App\Modules\StorefrontConfig\Contracts;

use App\Support\CommerceScope;

interface StorefrontConfigStore
{
    /** @return array{config: array<string, mixed>, version: int}|null */
    public function published(CommerceScope $scope): ?array;

}
