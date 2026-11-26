<?php

declare(strict_types=1);

namespace App\Modules\Headless\Infrastructure;

use App\Modules\Headless\Contracts\HeadlessSchemaReader;

final class NativeHeadlessSchemaReader implements HeadlessSchemaReader
{
    public function definition(): array
    {
        return HeadlessSchema::definition();
    }

    public function limits(): array
    {
        return ['max_query_bytes' => 20000, 'max_depth' => 8, 'max_complexity' => 80, 'read_only' => true];
    }
}
