<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

interface TransactionRunner
{
    public function transaction(callable $callback): mixed;
}
