<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ExtensionInterface;
use App\Modules\Extension\Application\ExtensionDiscovery;
use App\Modules\Extension\Application\ExtensionLifecycleService;
use App\Modules\Extension\Domain\ExtensionManifest;
use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;

/** Deterministic manifest gate; no database writes so it works on a fresh checkout. */
final class ExtensionCommand implements CommandInterface
{
    use CommandSupport;

    public string $signature = 'extension:check';
    public string $description = 'Validate discovered extension manifests';

    public function __construct(private readonly string $basePath = '')
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        unset($args);
        $base = $this->basePath !== '' ? $this->basePath : ((defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : (string) getcwd()));
        $validator = new ExtensionLifecycleService([]);
        $ok = true;
        $items = [];
        foreach (ExtensionDiscovery::classes($base) as $class) {
            try {
                $extension = new $class();
                $manifest = $this->manifest($extension);
                $errors = $validator->validate($manifest);
                if ($errors !== []) $ok = false;
                $items[] = ['id' => $manifest->id, 'version' => $manifest->version, 'ok' => $errors === [], 'errors' => $errors];
            } catch (\Throwable $exception) {
                $ok = false;
                $items[] = ['class' => $class, 'ok' => false, 'errors' => [$exception->getMessage()]];
            }
        }
        $this->write((string) json_encode(['ok' => $ok, 'extensions' => $items], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $ok ? 0 : 1;
    }

    private function manifest(ExtensionInterface $extension): ExtensionManifest
    {
        if (method_exists($extension, 'manifest')) {
            $raw = $extension->manifest();
            if (is_array($raw)) {
                $clean = [];
                foreach ($raw as $key => $value) if (is_string($key)) $clean[$key] = $value;
                return ExtensionManifest::fromArray($clean);
            }
        }
        return new ExtensionManifest($extension::id(), '1.0.0', ['core' => $extension::compatibleCore(), 'commerce' => $extension::compatibleCommerce()], [], []);
    }
}
