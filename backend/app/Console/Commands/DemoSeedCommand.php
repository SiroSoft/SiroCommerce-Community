<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Launch\Application\DemoSeedService;
use App\Modules\Launch\Domain\LaunchSeedRefused;
use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;
use Siro\Core\Database;

/** Idempotent launch demo data, shared with POST /api/admin/launch/seed-demo. */
final class DemoSeedCommand implements CommandInterface
{
    use CommandSupport;

    public string $signature = 'demo:seed';
    public string $description = 'Seed launch demo categories, products, pages and menu (idempotent)';

    public function __construct(private readonly string $basePath = '')
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $base = $this->basePath !== '' ? $this->basePath : (string) getcwd();
        try {
            $configFile = $base . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
            /** @var array<string, mixed> $config */
            $config = require $configFile;
            Database::configure($config);
        } catch (\Throwable $error) {
            $this->write('demo:seed failed to configure the database: ' . $error->getMessage());
            return 1;
        }

        try {
            $result = (new DemoSeedService())->seed();
        } catch (LaunchSeedRefused $error) {
            $this->write($error->getMessage());
            return 1;
        } catch (\Throwable $error) {
            $this->write('demo:seed failed: ' . $error->getMessage());
            return 1;
        }

        $this->write((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $created = array_sum($result['created']);
        $this->write($created === 0 ? 'Demo data already present; nothing created.' : 'Demo data seeded: ' . $created . ' records created.');
        return 0;
    }
}
