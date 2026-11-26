<?php

declare(strict_types=1);

namespace App\Modules\Headless\Contracts;

interface HeadlessSchemaReader
{
    /** @return array<string, mixed> */
    public function definition(): array;

    /** @return array{max_query_bytes: int, max_depth: int, max_complexity: int, read_only: bool} */
    public function limits(): array;
}
