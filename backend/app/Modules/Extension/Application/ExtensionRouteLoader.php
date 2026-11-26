<?php

declare(strict_types=1);

namespace App\Modules\Extension\Application;

use App\Contracts\ExtensionInterface;
use App\Extension\ExtensionRegistry;
use App\Middleware\ExtensionIdempotencyMiddleware;
use App\Models\Extension;

/**
 * Route mounting (spec RULE-E4): enabled extensions serve under
 * /api/ext/<vendor>/<name>/… with the mandatory middleware stack.
 * Best effort: a broken extension is skipped with a log, core routes live on.
 */
final class ExtensionRouteLoader
{
    /**
     * Mounted routes keyed by router instance: routes/api.php is re-required
     * per request (php -S) and per test, each with a fresh Router, so a
     * process-global set would wrongly skip mounting on later boots. WeakMap
     * avoids spl_object_id reuse after a router is garbage collected.
     * @var \WeakMap<object, array<string, true>>|null
     */
    private static ?\WeakMap $mounted = null;

    /**
     * @param object $router Siro\Core\Router
     * @param array<int, mixed> $fe Base frontend middleware stack
     */
    public static function mount(object $router, array $fe): void
    {
        if (!method_exists($router, 'group')) return;
        try {
            $ids = self::enabledIds();
        } catch (\Throwable $e) {
            error_log('[extensions] route mount skipped: ' . $e->getMessage());
            return;
        }
        foreach ($ids as $id) {
            try {
                self::mountOne($router, $fe, $id);
            } catch (\Throwable $e) {
                error_log('[extensions] route mount failed for ' . $id . ': ' . $e->getMessage());
            }
        }
    }

    private static function mountOne(object $router, array $fe, string $id): void
    {
        $extension = ExtensionRegistry::extension($id);
        $class = $extension !== null ? get_class($extension) : self::classFor($id);
        if ($class === null) return;
        $file = self::routesFile($class);
        if ($file === null) return;
        /** @var mixed $rows */
        $rows = require $file;
        if (!is_array($rows)) return;
        $prefix = '/api/ext/' . $id;
        foreach ($rows as $row) {
            $route = self::parseRow($row);
            if ($route === null) continue;
            [$method, $path, $handler, $middleware] = $route;
            $full = $prefix . '/' . ltrim($path, '/');
            $key = strtoupper($method) . ' ' . $full;
            $mounted = self::$mounted ??= new \WeakMap();
            $routerRoutes = $mounted[$router] ?? [];
            if (isset($routerRoutes[$key])) {
                throw new \RuntimeException('Extension route collision: ' . $key);
            }
            $routerRoutes[$key] = true;
            $mounted[$router] = $routerRoutes;
            $stack = self::middleware($fe, $middleware, $method);
            $router->group($prefix, $stack, static function ($group) use ($method, $path, $handler): void {
                $routeMethod = strtolower($method);
                $group->{$routeMethod}('/' . ltrim($path, '/'), $handler);
            });
        }
    }

    /**
     * @param mixed $row
     * @return array{0: string, 1: string, 2: mixed, 3: list<string>}|null
     */
    private static function parseRow(mixed $row): ?array
    {
        if (!is_array($row) || count($row) < 3) return null;
        [$method, $path, $handler] = [$row[0], $row[1], $row[2]];
        $middleware = $row[3] ?? ['auth', 'throttle:60,1'];
        if (!is_string($method) || !in_array(strtoupper($method), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) return null;
        if (!is_string($path) || $path === '' || str_starts_with($path, '/') || str_contains($path, '..')) return null;
        if (!is_array($handler) || count($handler) !== 2 || !is_string($handler[0]) || !is_string($handler[1])) return null;
        if (!class_exists($handler[0]) || !method_exists($handler[0], $handler[1])) return null;
        if (!is_array($middleware)) return null;
        foreach ($middleware as $item) if (!is_string($item)) return null;
        /** @var list<string> $middleware */
        return [strtoupper($method), $path, $handler, $middleware];
    }

    /**
     * @param array<int, mixed> $fe
     * @param list<string> $middleware
     * @return array<int, mixed>
     */
    private static function middleware(array $fe, array $middleware, string $method): array
    {
        $stack = $fe;
        foreach ($middleware as $item) {
            if ($item === 'auth') {
                $stack[] = 'auth';
            } elseif (str_starts_with($item, 'cap:')) {
                $stack[] = 'auth';
                $stack[] = $item;
            } elseif (str_starts_with($item, 'throttle:')) {
                $stack[] = $item;
            }
        }
        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $stack[] = ExtensionIdempotencyMiddleware::class;
        }
        return $stack;
    }

    /** @return class-string<ExtensionInterface>|null */
    private static function classFor(string $id): ?string
    {
        foreach (ExtensionDiscovery::classes(self::basePath()) as $class) {
            try {
                $extension = new $class();
                if ($extension::id() === $id) return $class;
            } catch (\Throwable) {
                continue;
            }
        }
        return null;
    }

    /** @param class-string $class */
    private static function routesFile(string $class): ?string
    {
        try {
            $file = (new \ReflectionClass($class))->getFileName();
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($file)) return null;
        $candidate = dirname($file) . DIRECTORY_SEPARATOR . 'routes.php';
        if (is_file($candidate)) return $candidate;
        $nested = dirname($file) . DIRECTORY_SEPARATOR . basename(dirname($file)) . DIRECTORY_SEPARATOR . 'routes.php';
        return is_file($nested) ? $nested : null;
    }

    /** @return list<string> */
    private static function enabledIds(): array
    {
        $rows = Extension::query()->where('state', 'enabled')->get();
        $ids = [];
        foreach ($rows as $row) {
            $data = $row instanceof \Siro\Core\Model ? $row->toArray() : (array) $row;
            if (is_string($data['id'] ?? null)) $ids[] = $data['id'];
        }
        return $ids;
    }

    private static function basePath(): string
    {
        return defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : (string) getcwd();
    }
}
