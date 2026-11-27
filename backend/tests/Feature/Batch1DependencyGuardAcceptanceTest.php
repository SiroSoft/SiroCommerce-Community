<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Extension\ExtensionRegistry;
use App\Models\Extension;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Extension\Application\ExtensionLifecycleService;
use App\Modules\Extension\Application\ExtensionQualityGate;
use App\Modules\Extension\Domain\ExtensionManifest;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;
use ZipArchive;

/**
 * Batch 1 Platform §2.2 dependency guard — ACCEPTANCE criteria (user
 * decision 26 Nov 2026 ~18:50 ICT). One test per criterion, HTTP-level
 * where the criterion names an HTTP status. Uses the REAL Cart ids
 * (siro/abandoned-cart / siro/abandoned-cart-pro) as the modelled pair:
 * the guard is generic, Cart is its first user, and no Cart code exists
 * yet in Wave 0 — the manifests here are stand-ins, not plugin code.
 * (License is deliberately NOT declared on the Pro stand-in: license
 * enforcement is out of guard scope and covered by Affiliate tests.)
 */
final class Batch1DependencyGuardAcceptanceTest extends TestCase
{
    private const FREE = 'siro/abandoned-cart';
    private const PRO = 'siro/abandoned-cart-pro';

    private Router $router;
    /** @var array<string, string> */
    private array $adminHeaders = [];
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        ExtensionRegistry::reset();
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';

        $email = 'guard-admin-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'guard-admin', 'email' => $email, 'password' => 'secret123', 'role' => 'admin']);
        $tokens = $auth->login($email, 'secret123');
        $this->adminHeaders = [
            'Authorization' => 'Bearer ' . $tokens->access,
            'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND',
        ];
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-guard-acc-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        ExtensionRegistry::reset();
        $this->removeDir($this->root);
        parent::tearDown();
    }

    private function service(): ExtensionLifecycleService
    {
        return new ExtensionLifecycleService([]);
    }

    /** @param list<string> $requiresExtensions @param list<string> $permissions */
    private function manifest(string $id, array $requiresExtensions = [], array $permissions = []): ExtensionManifest
    {
        return ExtensionManifest::fromArray([
            'id' => $id,
            'version' => '1.0.0',
            'requires' => ['core' => '^1.0', 'commerce' => '^0.3'],
            'capabilities' => [],
            'permissions' => $permissions,
            'requires_extensions' => $requiresExtensions,
        ]);
    }

    private function installBoth(): void
    {
        $service = $this->service();
        $service->install($this->manifest(self::FREE, [], ['abandoned-cart:manage']));
        $service->install($this->manifest(self::PRO, [self::FREE], ['abandoned-cart-pro:manage']));
    }

    private function enableBoth(): void
    {
        $service = $this->service();
        $service->enable(self::FREE);
        $service->enable(self::PRO);
    }

    private function state(string $id): ?string
    {
        $row = Extension::find($id);
        return $row === null ? null : ($row->toArray()['state'] ?? null);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, json: mixed}
     */
    private function call(string $method, string $path, array $body = []): array
    {
        $headers = $this->adminHeaders + ['Idempotency-Key' => 'guard-' . bin2hex(random_bytes(4))];
        $request = new Request($method, $path, [], $headers, $body, '127.0.0.1');
        try {
            $response = $this->router->dispatch($request);
        } catch (ValidationException $exception) {
            $response = $exception->toResponse();
        }
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();
        return ['status' => $response->statusCode(), 'json' => json_decode($output, true)];
    }

    /** Criterion 1: enable Pro while free is not enabled -> 422 + clear reason. */
    public function testCriterion1_EnableProWithoutFreeReturns422(): void
    {
        $this->installBoth();
        $res = $this->call('POST', '/api/admin/extensions/enable', ['id' => self::PRO]);
        self::assertSame(422, $res['status'], (string) json_encode($res['json']));
        $message = (string) ($res['json']['message'] ?? '');
        self::assertStringContainsString(self::FREE, $message);
        self::assertStringContainsString('to be enabled', $message);
        self::assertSame('installed', $this->state(self::PRO));
    }

    /** Criterion 2: disable/uninstall free while Pro is enabled -> 422. */
    public function testCriterion2_DisableAndUninstallFreeWhileProEnabledReturn422(): void
    {
        $this->installBoth();
        $this->enableBoth();
        $disabled = $this->call('POST', '/api/admin/extensions/disable', ['id' => self::FREE]);
        self::assertSame(422, $disabled['status'], (string) json_encode($disabled['json']));
        self::assertStringContainsString(self::PRO, (string) ($disabled['json']['message'] ?? ''));
        $uninstalled = $this->call('POST', '/api/admin/extensions/uninstall', ['id' => self::FREE, 'remove_data' => false]);
        self::assertSame(422, $uninstalled['status'], (string) json_encode($uninstalled['json']));
        self::assertStringContainsString(self::PRO, (string) ($uninstalled['json']['message'] ?? ''));
        $uninstalledRemove = $this->call('POST', '/api/admin/extensions/uninstall', ['id' => self::FREE, 'remove_data' => true]);
        self::assertSame(422, $uninstalledRemove['status'], (string) json_encode($uninstalledRemove['json']));
        self::assertSame('enabled', $this->state(self::FREE));
        self::assertSame('enabled', $this->state(self::PRO));
    }

    /**
     * Criterion 3: disabling Pro falls back to basic (free stays enabled and
     * usable) and Pro data survives untouched — table + rows still there,
     * and Pro can be re-enabled afterwards with its data intact.
     */
    public function testCriterion3_DisableProKeepsDataAndBasicFlowRuns(): void
    {
        $this->installBoth();
        $this->enableBoth();
        Database::connection()->exec(
            "CREATE TABLE ext_abandoned_cart_pro_segments (id TEXT PRIMARY KEY, store_id TEXT NOT NULL, channel_id TEXT NOT NULL, name TEXT NOT NULL)"
        );
        Database::connection()->exec(
            "INSERT INTO ext_abandoned_cart_pro_segments (id, store_id, channel_id, name) VALUES ('seg-1', 'default', 'web', 'VIP')"
        );
        $disabled = $this->call('POST', '/api/admin/extensions/disable', ['id' => self::PRO]);
        self::assertSame(200, $disabled['status'], (string) json_encode($disabled['json']));
        self::assertSame('disabled', $this->state(self::PRO));
        // Basic still runs: free stays enabled.
        self::assertSame('enabled', $this->state(self::FREE));
        // Pro table + data survive the disable (nothing dropped).
        $tables = Database::connection()->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='ext_abandoned_cart_pro_segments'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['ext_abandoned_cart_pro_segments'], $tables);
        $rows = Database::connection()->query(
            "SELECT name FROM ext_abandoned_cart_pro_segments WHERE id = 'seg-1'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['VIP'], $rows);
        // Pro can be re-enabled with its data intact.
        $reEnabled = $this->call('POST', '/api/admin/extensions/enable', ['id' => self::PRO]);
        self::assertSame(200, $reEnabled['status'], (string) json_encode($reEnabled['json']));
        self::assertSame('enabled', $this->state(self::PRO));
        $rows = Database::connection()->query(
            "SELECT name FROM ext_abandoned_cart_pro_segments WHERE id = 'seg-1'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['VIP'], $rows);
    }

    /**
     * Criterion 4: a NEW package built with the guard field
     * (requires_extensions) and a scoped ext_* migration passes QIT,
     * including store-scope; the same package without scope fails it
     * (negative control proving the check can catch a violation).
     */
    public function testCriterion4_NewPackagePassesQitStoreScope(): void
    {
        $scopedMigration = <<<'PHP'
            <?php
            use Siro\Core\DB\Blueprint;
            use Siro\Core\Schema;
            return new class {
                public function up(): void
                {
                    Schema::create('ext_abandoned_cart_pro_segments', function (Blueprint $t): void {
                        $t->string('id', 26);
                        $t->string('store_id', 64);
                        $t->string('channel_id', 64);
                        $t->string('name', 120);
                        $t->primary('id');
                        $t->index(['store_id', 'channel_id']);
                    });
                }
                public function down(): void
                {
                    Schema::drop('ext_abandoned_cart_pro_segments');
                }
            };
            PHP;
        $zip = $this->package(
            ['requires_extensions' => [self::FREE]],
            ['migrations/20261126000061_create_ext_abandoned_cart_pro_segments.php' => $scopedMigration]
        );
        $report = (new ExtensionQualityGate($this->root))->run($zip);
        self::assertTrue($report['ok'], (string) json_encode($report));
        $checks = array_column($report['checks'], 'ok', 'name');
        self::assertTrue($checks['package'] ?? false, 'package check must pass');
        self::assertTrue($checks['store-scope'] ?? false, 'store-scope check must pass');

        // Negative control: same package, migration without scope -> QIT fails store-scope.
        $unscopedMigration = <<<'PHP'
            <?php
            use Siro\Core\DB\Blueprint;
            use Siro\Core\Schema;
            return new class {
                public function up(): void
                {
                    Schema::create('ext_abandoned_cart_pro_segments', function (Blueprint $t): void {
                        $t->string('id', 26);
                        $t->string('name', 120);
                        $t->primary('id');
                    });
                }
                public function down(): void
                {
                    Schema::drop('ext_abandoned_cart_pro_segments');
                }
            };
            PHP;
        $badZip = $this->package(
            ['requires_extensions' => [self::FREE]],
            ['migrations/20261126000061_create_ext_abandoned_cart_pro_segments.php' => $unscopedMigration]
        );
        $badReport = (new ExtensionQualityGate($this->root))->run($badZip);
        self::assertFalse($badReport['ok']);
        $badChecks = array_column($badReport['checks'], 'ok', 'name');
        self::assertFalse($badChecks['store-scope'] ?? true, 'store-scope must fail without store_id/channel_id');
    }

    /**
     * @param array<string, mixed> $manifestOverrides
     * @param array<string, string> $extraFiles
     */
    private function package(array $manifestOverrides, array $extraFiles): string
    {
        $manifest = array_merge([
            'schema' => 'siro.extension/2',
            'id' => self::PRO,
            'type' => 'module',
            'name' => 'Abandoned Cart Pro',
            'version' => '1.0.0',
            'provider' => 'App\\Extensions\\Siro\\AbandonedCartPro\\Extension',
            'requires' => ['core' => '^1.0', 'commerce' => '^0.3'],
            'capabilities' => [],
            'permissions' => ['abandoned-cart-pro:manage'],
            'migrations' => ['migrations/20261126000061_create_ext_abandoned_cart_pro_segments.php'],
            'routes' => ['routes.php'],
        ], $manifestOverrides);
        $files = array_merge([
            'manifest.json' => (string) json_encode($manifest, JSON_THROW_ON_ERROR),
            'Extension.php' => "<?php\n\nnamespace App\\Extensions\\Siro\\AbandonedCartPro;\n\nfinal class Extension\n{\n}\n",
            'routes.php' => "<?php\nreturn [['GET', 'status', [StatusController::class, 'index'], ['auth', 'cap:abandoned-cart-pro:manage']]];\n",
        ], $extraFiles);
        $path = $this->root . DIRECTORY_SEPARATOR . 'pro-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($files as $name => $content) $zip->addFromString($name, $content);
        // Installer verify requires checksums.json (sha256 of every other file).
        $checksums = [];
        foreach ($files as $name => $content) $checksums[$name] = hash('sha256', $content);
        $zip->addFromString('checksums.json', (string) json_encode($checksums, JSON_THROW_ON_ERROR));
        $zip->close();
        return $path;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
