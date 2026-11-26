<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Siro\Core\Database;
use Siro\Core\Lang;
use Siro\Core\Cache;

/**
 * Commerce test base: per-class SQLite file, real migrations from
 * database/migrations/ (spec: migrations are the single schema source).
 */
abstract class TestCase extends BaseTestCase
{
    private static bool $testArtifactCleanupRegistered = false;
    private static bool $mysqlDatabaseReady = false;
    private static bool $mysqlSchemaReady = false;
    private static string $mysqlSchemaKey = '';
    private static string $mysqlSchemaFingerprint = '';
    /** @var array<string, array<int, string>> */
    private static array $mysqlTables = [];

    protected static function markMySqlSchemaDirty(): void
    {
        self::$mysqlSchemaReady = false;
        self::$mysqlSchemaKey = '';
        self::$mysqlSchemaFingerprint = '';
        self::$mysqlTables = [];
    }

    protected function setUp(): void
    {
        parent::setUp();
        putenv('JWT_SECRET=test-secret-for-sirocommerce-phase2-tests-32c');
        $_ENV['JWT_SECRET'] = 'test-secret-for-sirocommerce-phase2-tests-32c';
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $rateDir = dirname(__DIR__) . '/storage/rate_limit';
        foreach (glob($rateDir . '/*.json') ?: [] as $rateFile) {
            @unlink($rateFile);
        }
        \Siro\Core\Router::setMiddlewareAliases([
            'auth' => \App\Middleware\AuthMiddleware::class,
            'throttle' => \App\Middleware\SecureThrottleMiddleware::class,
            'cors' => \Siro\Core\Middleware\CorsMiddleware::class,
            'json' => \Siro\Core\Middleware\JsonMiddleware::class,
            'cap' => \App\Middleware\CapabilityMiddleware::class,
        ]);
        Lang::setLocale('en');
        Lang::setFallbackLocale('en');
        try {
            Lang::boot(dirname(__DIR__));
        } catch (\Throwable) {
        }
        Database::purgeAll();
        Cache::reset();
        // Core TESTING.md discipline: Gate abilities and the Mail provider /
        // fake outbox must not leak between tests (cap middleware registers
        // abilities lazily; Mailer bridges env on send).
        \Siro\Core\Gate::reset();
        \Siro\Core\Mail::reset();
        \App\Support\Mailer::reset();
        $driver = strtolower((string) (getenv('TEST_DB_DRIVER') ?: 'sqlite'));
        if ($driver === 'mysql') {
            $database = $this->mysqlTestDatabase();
            putenv('TEST_DB_EFFECTIVE_DATABASE=' . $database);
            $_ENV['TEST_DB_EFFECTIVE_DATABASE'] = $database;
            if (!self::$mysqlDatabaseReady) {
                $this->ensureMySqlDatabase($database);
                self::$mysqlDatabaseReady = true;
            }
            Database::configure([
                'driver' => 'mysql',
                'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
                'database' => $database,
                'username' => getenv('TEST_DB_USERNAME') ?: 'root',
                'password' => getenv('TEST_DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
            ]);
            $schemaKey = $database;
            if (!self::$mysqlSchemaReady || self::$mysqlSchemaKey !== $schemaKey) {
                $this->resetMySqlSchema();
                $this->runMigrations();
                self::$mysqlTables[$database] = $this->mysqlTableList();
                self::$mysqlSchemaFingerprint = $this->mysqlSchemaFingerprint();
                self::$mysqlSchemaReady = true;
                self::$mysqlSchemaKey = $schemaKey;
            } else {
                if ($this->mysqlSchemaFingerprint() !== self::$mysqlSchemaFingerprint) {
                    $this->resetMySqlSchema();
                    $this->runMigrations();
                    self::$mysqlTables[$database] = $this->mysqlTableList();
                    self::$mysqlSchemaFingerprint = $this->mysqlSchemaFingerprint();
                } else {
                    $this->truncateMySqlSchema();
                }
            }
        } else {
            $file = $this->dbFile();
            if (is_file($file)) {
                @unlink($file);
            }
            $dir = dirname($file);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $template = $dir . '/schema-template-' . getmypid() . '.db';
            if (!is_file($template)) {
                Database::configure([
                    'driver' => 'sqlite',
                    'host' => '127.0.0.1',
                    'port' => 0,
                    'database' => $template,
                    'username' => '',
                    'password' => '',
                    'charset' => 'utf8',
                ]);
                $this->runMigrations();
                Database::purgeAll();
            }
            if (!copy($template, $file)) {
                throw new \RuntimeException('Could not create isolated SQLite test database.');
            }
            Database::configure([
                'driver' => 'sqlite',
                'host' => '127.0.0.1',
                'port' => 0,
                'database' => $file,
                'username' => '',
                'password' => '',
                'charset' => 'utf8',
            ]);
            if (!self::$testArtifactCleanupRegistered) {
                self::$testArtifactCleanupRegistered = true;
                register_shutdown_function(static function () use ($dir): void {
                    foreach (glob($dir . '/schema-template-' . getmypid() . '.db*') ?: [] as $artifact) {
                        @unlink($artifact);
                    }
                });
            }
        }
    }

    protected function tearDown(): void
    {
        try {
            if (strtolower((string) (getenv('TEST_DB_DRIVER') ?: 'sqlite')) === 'mysql' && Database::connections() !== []) {
                $pdo = Database::connection();
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                self::assertFalse($pdo->inTransaction(), 'A test left a MySQL transaction open.');
            }
        } finally {
            Database::purgeAll();
            \Siro\Core\Schema::resetPdo();
        }
        $file = $this->dbFile();
        if (is_file($file)) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function dbFile(): string
    {
        $test = md5($this->name() . ':' . getmypid());
        return dirname(__DIR__) . '/storage/tests/' . str_replace('\\', '_', static::class) . '_' . $test . '.db';
    }

    private function mysqlTestDatabase(): string
    {
        $base = (string) (getenv('TEST_DB_DATABASE') ?: 'sirocommerce_test');
        if ((getenv('TEST_DB_ISOLATE') ?: '1') === '0') {
            return $base;
        }

        $base = preg_replace('/[^A-Za-z0-9_]/', '_', $base) ?: 'sirocommerce_test';
        return substr($base, 0, 54) . '_p' . getmypid();
    }

    private function ensureMySqlDatabase(string $database): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
            throw new \RuntimeException('Invalid TEST_DB_DATABASE name.');
        }

        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: 3306);
        $username = getenv('TEST_DB_USERNAME') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';
        $server = new \PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
            $username,
            $password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $server->exec('CREATE DATABASE IF NOT EXISTS `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    private function runMigrations(): void
    {
        $files = glob(dirname(__DIR__) . '/database/migrations/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $migration = require $file;
            if (is_object($migration) && method_exists($migration, 'up')) {
                $migration->up();
            }
        }
    }

    private function resetMySqlSchema(): void
    {
        $pdo = Database::connection();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            if (is_string($table) && preg_match('/^[A-Za-z0-9_]+$/', $table) === 1) {
                $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        self::$mysqlTables = [];
    }

    private function truncateMySqlSchema(): void
    {
        $pdo = Database::connection();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $database = (string) (getenv('TEST_DB_EFFECTIVE_DATABASE') ?: '');
        // Rollback-focused tests can alter the table set within a class.
        $tables = $this->mysqlTableList();
        self::$mysqlTables[$database] = $tables;
        if ($tables !== []) {
            $triggerTables = $this->mysqlTriggerTables();
            $statements = array_map(
                static fn(string $table): string => in_array($table, $triggerTables, true)
                    ? 'TRUNCATE TABLE `' . $table . '`'
                    : 'DELETE FROM `' . $table . '`',
                $tables
            );
            if (in_array('users', $tables, true)) {
                $statements[] = 'ALTER TABLE `users` AUTO_INCREMENT = 1';
            }
            $pdo->exec(implode(';', $statements));
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /** @return array<int, string> */
    private function mysqlTableList(): array
    {
        $tables = Database::connection()->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_filter(
            $tables,
            static fn(mixed $table): bool => is_string($table) && preg_match('/^[A-Za-z0-9_]+$/', $table) === 1
        ));
    }

    /** @return array<int, string> */
    private function mysqlTriggerTables(): array
    {
        $statement = Database::connection()->query(
            'SELECT DISTINCT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'
        );
        $tables = $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_filter(
            $tables,
            static fn(mixed $table): bool => is_string($table) && preg_match('/^[A-Za-z0-9_]+$/', $table) === 1
        ));
    }

    private function mysqlSchemaFingerprint(): string
    {
        $pdo = Database::connection();
        $statement = $pdo->query(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY '
            . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() '
            . 'ORDER BY TABLE_NAME, ORDINAL_POSITION'
        );
        $columns = $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_NUM);
        $indexStatement = $pdo->query(
            'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, COLLATION, SUB_PART '
            . 'FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() '
            . 'ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX'
        );
        $indexes = $indexStatement === false ? [] : $indexStatement->fetchAll(\PDO::FETCH_NUM);
        return sha1(serialize([$columns, $indexes]));
    }
}
