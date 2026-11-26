<?php

declare(strict_types=1);

namespace App\Modules\Headless\Contracts;

interface HeadlessQueryReader
{
    /**
     * @param array<string, mixed>|null $user
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    public function execute(string $source, ?array $user, array $variables = []): array;
}
