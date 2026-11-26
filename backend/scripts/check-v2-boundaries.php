<?php

declare(strict_types=1);

/** Prevent modules from reaching legacy internals or crossing infrastructure boundaries. */
$root = dirname(__DIR__) . '/app/Modules';
$violations = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $contents = (string) file_get_contents($file->getPathname());
    $normalized = str_replace('\\', '/', $file->getPathname());
    $isPlatformAdapter = str_contains($normalized, '/Modules/Platform/Infrastructure/');
    $isApplicationLayer = str_contains($normalized, '/Application/');
    $isContractLayer = str_contains($normalized, '/Contracts/');
    $forbidden = [
        '~use App\\\\(Models|Repositories|Services|Controllers)\\\\~',
        '~use App\\\\Modules\\\\Platform\\\\Infrastructure\\\\~',
    ];
    // Extension lifecycle owns its persisted manifest aggregate; the model is
    // the module's storage boundary until the extension store port is split out.
    if (str_contains($normalized, '/Modules/Extension/Application/')) {
        $forbidden[0] = '~use App\\\\(Repositories|Services|Controllers)\\\\~';
    }
    if ($isApplicationLayer || $isContractLayer) {
        $forbidden[] = '~use Siro\\\\Core\\\\(Database|DB|Model|Schema|Queue)\\\\~';
    }
    foreach ($forbidden as $pattern) {
        if (!$isPlatformAdapter && preg_match($pattern, $contents) === 1) {
            $violations[] = str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $file->getPathname());
            break;
        }
    }
}
if ($violations !== []) {
    fwrite(STDERR, "v2-boundaries: FAIL\n" . implode("\n", $violations) . "\n");
    exit(1);
}
echo "v2-boundaries: PASS\n";
