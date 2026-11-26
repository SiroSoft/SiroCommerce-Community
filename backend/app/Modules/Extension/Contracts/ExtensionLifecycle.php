<?php

declare(strict_types=1);

namespace App\Modules\Extension\Contracts;

use App\Modules\Extension\Domain\ExtensionManifest;

interface ExtensionLifecycle
{
    public function install(ExtensionManifest $manifest): void;

    public function enable(string $extensionId): void;

    public function disable(string $extensionId): void;

    public function upgrade(ExtensionManifest $manifest): void;

    public function uninstall(string $extensionId, bool $removeData): void;
}
