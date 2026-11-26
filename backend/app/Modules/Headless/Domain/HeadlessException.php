<?php

declare(strict_types=1);

namespace App\Modules\Headless\Domain;

final class HeadlessException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
