<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Modules\Catalog\Contracts\DigitalDeliveryTransport;
use App\Support\Arr;
use Siro\Core\Env;

final class LocalDigitalDeliveryTransport implements DigitalDeliveryTransport
{
    /** @return array{content: string, mime: string, filename: string} */
    public function read(array $asset): array
    {
        $root = realpath((string) Env::get('DIGITAL_DELIVERY_ROOT', ''));
        $url = Arr::str($asset, 'url');
        $relative = parse_url($url, PHP_URL_SCHEME) === null && parse_url($url, PHP_URL_HOST) === null ? ltrim($url, '/\\') : '';
        if ($root === false || $relative === '' || str_contains($relative, "\0")) throw new \RuntimeException('Protected digital delivery transport is not configured');
        $path = realpath($root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative));
        if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) throw new \RuntimeException('Digital asset is unavailable');
        $maxBytes = is_numeric(Env::get('DIGITAL_DELIVERY_MAX_BYTES', '52428800')) ? max(1, (int) Env::get('DIGITAL_DELIVERY_MAX_BYTES', '52428800')) : 52428800;
        $size = filesize($path);
        if ($size === false || $size > $maxBytes) throw new \RuntimeException('Digital asset exceeds delivery limit');
        $content = file_get_contents($path);
        if ($content === false) throw new \RuntimeException('Digital asset is unavailable');
        $filename = basename(Arr::str($asset, 'name', basename($path)));
        return ['content' => $content, 'mime' => function_exists('mime_content_type') ? ((string) mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream', 'filename' => $filename];
    }
}
