<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain;

final class EventSchemaRegistry
{
    /** @var array<string,array<int,array<int,string>>> */
    private array $schemas = [];

    /** @param array<int,string> $requiredFields */
    public function register(string $name, int $version, array $requiredFields): void
    {
        if (preg_match('/^[a-z0-9_.-]+$/', $name) !== 1 || $version < 1) throw new \InvalidArgumentException('Invalid event schema.');
        $this->schemas[$name][$version] = array_values(array_unique($requiredFields));
    }

    /** @param array<string,mixed> $payload */
    public function assertCompatible(string $name, int $version, array $payload): void
    {
        $versions = $this->schemas[$name] ?? [];
        if ($versions === []) throw new \InvalidArgumentException('Unknown integration event schema.');
        $supported = array_filter(array_keys($versions), static fn (int $candidate): bool => $candidate <= $version);
        if ($supported === []) throw new \InvalidArgumentException('Integration event schema version is not compatible.');
        $required = $versions[max($supported)];
        foreach ($required as $field) if (!array_key_exists($field, $payload)) throw new \InvalidArgumentException('Integration event payload is incompatible.');
    }
}
