<?php
declare(strict_types=1);
namespace App\Modules\Smart\Contracts;
interface SmartOperations
{
    /** @return array<string, mixed> */ public function actionCenter(): array;
    /** @return array<string, mixed> */ public function rescue(string $id, string $actor): array;
    /** @return array<string, mixed> */ public function search(string $query): array;
    /** @return array{ok: bool, message: string} */ public function execute(string $command, string $actor): array;
    /** @return array<int, array<string, mixed>> */ public function inventory(): array;
    /** @return array<int, array<string, mixed>> */ public function setup(): array;
    /** @return array<int, array<string, mixed>> */ public function conversion(): array;
}
