<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Contracts\TransactionalMessageProviderInterface;
use Siro\Core\Mail;

final class NativeTransactionalMessageProvider implements TransactionalMessageProviderInterface
{
    public function send(string $recipient, string $subject, string $html): void
    {
        if (!Mail::to($recipient)->subject($subject)->html($html)->send()) {
            throw new \RuntimeException('Transactional message provider rejected delivery');
        }
    }
}
