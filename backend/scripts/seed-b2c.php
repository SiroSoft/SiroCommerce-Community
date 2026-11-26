<?php

declare(strict_types=1);

use App\Support\B2cCatalogSeeder;
use Siro\Core\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

$driver = strtolower((string) (getenv('DB_CONNECTION') ?: 'mysql'));
if ($driver !== 'mysql') throw new RuntimeException('B2C seeding requires MySQL; set DB_CONNECTION=mysql.');
Database::configure(['driver' => $driver, 'host' => getenv('DB_HOST') ?: '127.0.0.1', 'port' => (int) (getenv('DB_PORT') ?: 3306), 'database' => getenv('DB_DATABASE') ?: 'sirocommerce', 'username' => getenv('DB_USERNAME') ?: 'root', 'password' => getenv('DB_PASSWORD') ?: '', 'charset' => 'utf8mb4']);
$result = (new B2cCatalogSeeder())->seed(Database::connection());
echo json_encode(['status' => 'PASS', 'scope' => ['store' => 'default', 'channel' => 'web', 'locale' => 'en', 'currency' => 'USD'], 'seeded' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
