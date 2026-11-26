<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\CatalogAltImageAttacher;
use Siro\Core\App;
use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;
use Siro\Core\Database;

final class AttachAltProductImagesCommand implements CommandInterface
{
    use CommandSupport;

    public string $signature = 'catalog:attach-alt-images';
    public string $description = 'Attach generated alternate SVG gallery frames to active catalog products';

    public function __construct(private readonly string $basePath) {}

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $dryRun = in_array('--dry-run', $args, true);
        // Containers ship the backend tree only; the storefront public assets
        // live in the released tarball. --force trusts the strict URL pattern
        // instead of a local file check (still traversal-safe).
        $requireFile = !in_array('--force', $args, true);
        try {
            $app = new App($this->basePath);
            $app->boot();
            $publicRoot = dirname($this->basePath) . DIRECTORY_SEPARATOR . 'apps' . DIRECTORY_SEPARATOR . 'storefront' . DIRECTORY_SEPARATOR . 'public';
            $summary = (new CatalogAltImageAttacher())->attach(Database::connection(), $publicRoot, $dryRun, $requireFile);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $this->write(json_encode(
            ['status' => 'PASS'] + $summary,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
        return 0;
    }
}
