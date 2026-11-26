<?php
declare(strict_types=1);
namespace App\Modules\Checkout\Contracts;
interface CartPort
{
    /** @return array<string, mixed>|null */ public function find(string $id): ?array;
    /** @return array<int, array<string, mixed>> */ public function lines(string $id): array;
    public function converted(string $id): void;
}
