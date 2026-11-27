<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Extension\Application\ProArtifactService;
use App\Modules\StorefrontConfig\Application\ThemePackageArchiveInstaller;
use Tests\TestCase;
use ZipArchive;

/**
 * Release packaging for the private Pro theme feed: the CLI must emit signed
 * siro.<key>.<version> archives that the runtime theme installer accepts, and
 * must fail closed when signing is unavailable or an asset is executable.
 */
final class EcosystemThemeArtifactTest extends TestCase
{
    private const ENV_KEYS = [
        'EXTENSION_SIGNING_PRIVATE_KEY',
        'EXTENSION_SIGNING_PUBLIC_KEYS',
        'EXTENSION_SIGNING_PUBLIC_KEY',
        'EXTENSION_SIGNING_KEY_ID',
        'EXTENSION_REQUIRE_SIGNATURE',
        'PRO_ARTIFACT_DIR',
    ];

    private string $root;

    private string $outDir;

    private string $privateKey = '';

    private string $publicKey = '';

    /** @var array<string, string> */
    private array $originalEnv = [];

    /** @var array<string, string> */
    private array $originalPutenv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ENV_KEYS as $key) {
            if (array_key_exists($key, $_ENV)) {
                $env = $_ENV[$key];
                $this->originalEnv[$key] = is_scalar($env) ? (string) $env : '';
            }
            $putenv = getenv($key);
            if ($putenv !== false) {
                $this->originalPutenv[$key] = $putenv;
            }
            $this->setEnv($key, null);
        }

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-theme-artifact-' . bin2hex(random_bytes(4));
        $this->outDir = $this->root . DIRECTORY_SEPARATOR . 'out';
        self::assertTrue(mkdir($this->root, 0775, true) || is_dir($this->root));

        if (function_exists('sodium_crypto_sign_keypair')) {
            $pair = sodium_crypto_sign_keypair();
            $this->privateKey = base64_encode(sodium_crypto_sign_secretkey($pair));
            $this->publicKey = base64_encode(sodium_crypto_sign_publickey($pair));
        }
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->setEnv($key, $this->originalEnv[$key] ?? null);
        }
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testPacksAndSignsBothProThemes(): void
    {
        $php = $this->requireSodium();
        $repo = dirname(__DIR__, 3);
        $script = $repo . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ecosystem-catalog' . DIRECTORY_SEPARATOR . 'package-themes.php';
        $atelier = $repo . DIRECTORY_SEPARATOR . 'examples' . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . 'atelier-noir';
        $apothecary = $repo . DIRECTORY_SEPARATOR . 'examples' . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . 'apothecary-calm';
        if (!is_dir($atelier) || !is_dir($apothecary)) {
            $this->markTestSkipped('Pro theme sources are not part of this edition.');
        }

        $env = getenv();
        $env['EXTENSION_SIGNING_PRIVATE_KEY'] = $this->privateKey;
        $env['EXTENSION_SIGNING_KEY_ID'] = 'key-1';
        unset($env['PRO_ARTIFACT_DIR']);

        $command = array_merge($php, [
            $script,
            '--theme=' . $atelier,
            '--theme=' . $apothecary,
            '--out=' . $this->outDir,
            '--key=key-1',
            '--json',
        ]);
        $result = $this->runProcess($command, $env);

        self::assertSame(0, $result['code'], $result['stderr']);
        $metadata = json_decode($result['stdout'], true);
        self::assertIsArray($metadata);
        self::assertSame(2, $metadata['count'] ?? null);
        self::assertIsArray($metadata['artifacts'] ?? null);

        $expected = [
            'siro/atelier-noir' => ['key' => 'atelier-noir', 'filename' => 'siro-atelier-noir-1.0.0.zip'],
            'siro/apothecary-calm' => ['key' => 'apothecary-calm', 'filename' => 'siro-apothecary-calm-1.0.0.zip'],
        ];

        $artifacts = [];
        foreach ($metadata['artifacts'] as $artifact) {
            self::assertIsArray($artifact);
            $artifacts[$artifact['id']] = $artifact;
        }
        self::assertSame(array_keys($expected), array_keys($artifacts));

        $this->setEnv('EXTENSION_SIGNING_PUBLIC_KEYS', (string) json_encode(['key-1' => $this->publicKey], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->setEnv('PRO_ARTIFACT_DIR', $this->outDir);
        $artifactService = new ProArtifactService(dirname(__DIR__, 2));

        foreach ($expected as $id => $info) {
            $artifact = $artifacts[$id];
            self::assertSame($info['key'], $artifact['key']);
            self::assertSame('1.0.0', $artifact['version']);
            self::assertSame($info['filename'], $artifact['filename']);
            self::assertTrue($artifact['signed']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $artifact['sha256']);
            self::assertIsInt($artifact['size']);
            self::assertGreaterThan(0, $artifact['size']);

            $zipPath = $this->outDir . DIRECTORY_SEPARATOR . $artifact['filename'];
            self::assertFileExists($zipPath);
            self::assertSame(hash_file('sha256', $zipPath), $artifact['sha256']);
            self::assertSame(filesize($zipPath), $artifact['size']);

            $report = (new ThemePackageArchiveInstaller())->verify($zipPath, true);
            self::assertTrue($report['signed']);
            self::assertSame($info['key'], $report['theme_id']);
            self::assertSame($id, $report['package']['id'] ?? null);

            $this->assertArchiveLayout($zipPath);

            // The generic private-feed service must recognize the signed theme.
            $meta = $artifactService->metadata([
                'id' => $id,
                'latest_version' => '1.0.0',
                'sha256' => $artifact['sha256'],
            ]);
            self::assertTrue($meta['available']);
            self::assertTrue($meta['signed']);
            self::assertSame($info['filename'], $meta['filename']);
            self::assertSame($artifact['sha256'], $meta['sha256']);
        }

        self::assertStringNotContainsString($this->privateKey, $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString($this->outDir, $result['stdout'] . $result['stderr']);
    }

    public function testFailsWithoutSigningKey(): void
    {
        $php = $this->requireSodium();
        $repo = dirname(__DIR__, 3);
        $script = $repo . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ecosystem-catalog' . DIRECTORY_SEPARATOR . 'package-themes.php';
        $theme = $repo . DIRECTORY_SEPARATOR . 'examples' . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . 'atelier-noir';
        $outDir = $this->root . DIRECTORY_SEPARATOR . 'nokey';

        $env = getenv();
        unset($env['EXTENSION_SIGNING_PRIVATE_KEY']);
        unset($env['EXTENSION_SIGNING_KEY_ID']);

        $result = $this->runProcess(array_merge($php, [
            $script,
            '--theme=' . $theme,
            '--out=' . $outDir,
            '--json',
        ]), $env);

        self::assertNotSame(0, $result['code']);
        self::assertStringContainsString('EXTENSION_SIGNING_PRIVATE_KEY', $result['stderr']);
        self::assertDirectoryDoesNotExist($outDir);
    }

    public function testRejectsExecutableAssets(): void
    {
        $php = $this->requireSodium();
        $repo = dirname(__DIR__, 3);
        $script = $repo . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ecosystem-catalog' . DIRECTORY_SEPARATOR . 'package-themes.php';
        $theme = $this->root . DIRECTORY_SEPARATOR . 'bad-theme';
        self::assertTrue(mkdir($theme . DIRECTORY_SEPARATOR . 'assets', 0775, true) || is_dir($theme . DIRECTORY_SEPARATOR . 'assets'));
        self::assertNotFalse(file_put_contents(
            $theme . DIRECTORY_SEPARATOR . 'theme.json',
            (string) json_encode([
                'schema' => 'siro.theme/1',
                'id' => 'acme/bad',
                'key' => 'bad',
                'version' => '1.0.0',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        ));
        self::assertNotFalse(file_put_contents($theme . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'shell.php', '<?php echo 1;'));

        $env = getenv();
        $env['EXTENSION_SIGNING_PRIVATE_KEY'] = $this->privateKey;
        $env['EXTENSION_SIGNING_KEY_ID'] = 'key-1';

        $result = $this->runProcess(array_merge($php, [
            $script,
            '--theme=' . $theme,
            '--out=' . $this->root . DIRECTORY_SEPARATOR . 'bad-out',
            '--key=key-1',
            '--json',
        ]), $env);

        self::assertNotSame(0, $result['code']);
        self::assertStringContainsStringIgnoringCase('unsupported theme asset', $result['stderr']);
    }

    private function assertArchiveLayout(string $zipPath): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath) === true);
        try {
            $names = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat)) {
                    continue;
                }
                $names[] = str_replace('\\', '/', (string) $stat['name']);
            }
        } finally {
            $zip->close();
        }

        self::assertContains('theme.json', $names);
        self::assertContains('checksums.json', $names);
        self::assertContains('signature.json', $names);
        foreach ($names as $name) {
            if (in_array($name, ['theme.json', 'checksums.json', 'signature.json'], true)) {
                continue;
            }
            self::assertStringStartsWith('assets/', $name);
        }
    }

    /**
     * @return list<string>
     */
    private function requireSodium(): array
    {
        if ($this->privateKey === '' || $this->publicKey === '') {
            self::markTestSkipped('ext-sodium is unavailable for the theme packaging CLI');
        }
        $php = $this->phpWithSodium();
        if ($php === null) {
            self::markTestSkipped('ext-sodium is unavailable for the theme packaging CLI');
        }

        return $php;
    }

    /**
     * @return list<string>|null
     */
    private function phpWithSodium(): ?array
    {
        $probe = $this->runProcess([PHP_BINARY, '-d', 'extension=sodium', '-r', 'exit(function_exists("sodium_crypto_sign_detached") ? 0 : 1);'], getenv());
        if ($probe['code'] === 0) {
            return [PHP_BINARY, '-d', 'extension=sodium'];
        }

        return function_exists('sodium_crypto_sign_detached') ? [PHP_BINARY] : null;
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command, array $env): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $this->root, $env);
        if (!is_resource($process)) {
            return ['code' => 1, 'stdout' => '', 'stderr' => 'Could not start process'];
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key]);
            putenv($key);
            return;
        }
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
