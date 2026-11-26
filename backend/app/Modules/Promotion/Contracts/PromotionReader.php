<?php
declare(strict_types=1);
namespace App\Modules\Promotion\Contracts;
interface PromotionReader
{
    /** @return array<string, mixed>|null */ public function findActive(string $code): ?array;
}
