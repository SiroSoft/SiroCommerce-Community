<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $rel = str_replace('\\', '/', substr($class, strlen('App\\'))) . '.php';
        foreach ([__DIR__ . '/../app/', __DIR__ . '/../src/'] as $base) {
            $path = $base . $rel;
            if (is_file($path)) {
                require $path;
                return;
            }
        }
        return;
    }
    if (str_starts_with($class, 'Tests\\')) {
        $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen('Tests\\'))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});
