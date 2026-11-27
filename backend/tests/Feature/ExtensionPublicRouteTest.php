<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\ExtensionIdempotencyMiddleware;
use App\Modules\Extension\Application\ExtensionPackageInstaller;
use App\Modules\Extension\Application\ExtensionQualityGate;
use App\Modules\Extension\Application\ExtensionRouteLoader;
use Tests\TestCase;

/**
 * The 'public' route token (Wave 0 follow-up, decided by the coordinator
 * 27 Nov 2026 for Email Capture Popup): a mutating extension route that
 * must serve anonymous guests carries the explicit 'public' token instead
 * of auth/cap. QIT accepts the marker, the loader mounts the route
 * without auth middleware. A mutation route with neither auth/cap nor
 * 'public' must still fail route-auth.
 */
final class ExtensionPublicRouteTest extends TestCase
{
    public function testPublicMutationRoutePassesRouteAuth(): void
    {
        $report = $this->runGateWithRoutes(<<<'PHP'
            <?php

            declare(strict_types=1);

            use App\Extensions\Siro\ContactWidget\ContactWidgetController;

            require_once __DIR__ . '/src/ContactWidgetService.php';
            require_once __DIR__ . '/src/ContactWidgetController.php';

            return [
                ['GET', 'public', [ContactWidgetController::class, 'publicData'], ['throttle:120,1']],
                ['POST', 'subscribe', [ContactWidgetController::class, 'publicData'], ['throttle:20,1', 'public']],
            ];
            PHP);
        self::assertTrue($this->checkOk($report, 'route-auth'), (string) json_encode($report));
    }

    public function testBareMutationRouteStillFailsRouteAuth(): void
    {
        $report = $this->runGateWithRoutes(<<<'PHP'
            <?php

            declare(strict_types=1);

            use App\Extensions\Siro\ContactWidget\ContactWidgetController;

            require_once __DIR__ . '/src/ContactWidgetService.php';
            require_once __DIR__ . '/src/ContactWidgetController.php';

            return [
                ['GET', 'public', [ContactWidgetController::class, 'publicData'], ['throttle:120,1']],
                ['POST', 'subscribe', [ContactWidgetController::class, 'publicData'], ['throttle:20,1']],
            ];
            PHP);
        self::assertFalse($this->checkOk($report, 'route-auth'), (string) json_encode($report));
    }

    public function testLoaderMountsPublicRouteWithoutAuth(): void
    {
        $method = new \ReflectionMethod(ExtensionRouteLoader::class, 'middleware');
        /** @var list<mixed> $publicStack */
        $publicStack = $method->invoke(null, [], ['throttle:20,1', 'public'], 'POST');
        self::assertNotContains('auth', $publicStack);
        self::assertContains('throttle:20,1', $publicStack);
        self::assertContains(ExtensionIdempotencyMiddleware::class, $publicStack);

        /** @var list<mixed> $authedStack */
        $authedStack = $method->invoke(null, [], ['auth', 'throttle:20,1'], 'POST');
        self::assertContains('auth', $authedStack);
    }

    /**
     * Pack a temp copy of ContactWidget with routes.php replaced, run the
     * real quality gate on it, and return the full report.
     * @return array{ok: bool, checks: list<array{name: string, ok: bool, details: list<string>}>}
     */
    private function runGateWithRoutes(string $routesPhp): array
    {
        $src = $this->basePath() . '/app/Extensions/Siro/ContactWidget';
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'public-route-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0777, true);
        $this->copyDir($src, $tmp);
        file_put_contents($tmp . '/routes.php', $routesPhp);
        $zip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'public-route-' . bin2hex(random_bytes(4)) . '.zip';
        try {
            $pack = (new ExtensionPackageInstaller($this->basePath()))->pack($tmp, $zip);
            self::assertTrue($pack['ok'], (string) json_encode($pack));
            return (new ExtensionQualityGate($this->basePath()))->run($zip);
        } finally {
            if (is_file($zip)) {
                @unlink($zip);
            }
            $this->removeDir($tmp);
        }
    }

    /** @param array{ok: bool, checks: list<array{name: string, ok: bool, details: list<string>}>} $report */
    private function checkOk(array $report, string $name): bool
    {
        foreach ($report['checks'] as $check) {
            if (($check['name'] ?? null) === $name) {
                return (bool) ($check['ok'] ?? false);
            }
        }
        return false;
    }

    private function basePath(): string
    {
        return dirname(__DIR__, 2);
    }

    private function copyDir(string $src, string $dst): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $target = $dst . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                mkdir($target, 0777, true);
            } else {
                copy((string) $item->getPathname(), $target);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                rmdir((string) $item->getPathname());
            } else {
                unlink((string) $item->getPathname());
            }
        }
        rmdir($dir);
    }
}
