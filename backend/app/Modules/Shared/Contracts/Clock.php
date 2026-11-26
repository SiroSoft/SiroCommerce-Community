<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

interface Clock
{
    public function now(): string;
}
