<?php

declare(strict_types=1);

namespace App\Modules\Extension\Domain;

final readonly class ExtensionManifest
{
    /**
     * @param array<string, string> $requires
     * @param list<string> $capabilities
     * @param list<string> $permissions
     * @param list<string> $migrations
     * @param list<string> $routes
     * @param array{label: string, section: string, panels: list<array{key: string, title: string, type: string, endpoint: string, permission: string}>} $admin
     * @param array<string, mixed> $license Supported keys: required (bool), sku (string).
     */
    public function __construct(
        public string $id,
        public string $version,
        public array $requires,
        public array $capabilities,
        public array $permissions,
        public array $migrations = [],
        public array $routes = [],
        public array $admin = [],
        public array $license = [],
    ) {
        if ($this->id === '' || $this->version === '') throw new \InvalidArgumentException('Extension id and version are required');
        foreach ([$this->capabilities, $this->permissions, $this->migrations, $this->routes] as $items) {
            foreach ($items as $item) if ($item === '' || trim($item) !== $item) throw new \InvalidArgumentException('Extension manifest lists must contain non-empty strings');
        }
        if ($this->admin !== []) self::validateAdmin($this->admin, $this->permissions);
        if ($this->license !== []) self::validateLicense($this->license);
    }

    /** @param array<string, mixed> $license */
    private static function validateLicense(array $license): void
    {
        if (array_key_exists('required', $license) && !is_bool($license['required'])) throw new \InvalidArgumentException('Extension license required flag must be a boolean');
        if (array_key_exists('sku', $license) && !is_string($license['sku'])) throw new \InvalidArgumentException('Extension license sku must be a string');
    }

    /** @param array<string, mixed> $admin @param list<string> $permissions */
    private static function validateAdmin(array $admin, array $permissions): void
    {
        $label = $admin['label'] ?? null;
        if (!is_string($label) || mb_strlen(trim($label)) < 2 || mb_strlen($label) > 48) throw new \InvalidArgumentException('Extension admin label is invalid');
        $section = $admin['section'] ?? 'Extensions';
        if (!is_string($section) || mb_strlen(trim($section)) < 2 || mb_strlen($section) > 48) throw new \InvalidArgumentException('Extension admin section is invalid');
        $panels = $admin['panels'] ?? [];
        if (!is_array($panels) || count($panels) > 8) throw new \InvalidArgumentException('Extension admin panels are invalid');
        $keys = [];
        foreach ($panels as $panel) {
            if (!is_array($panel)) throw new \InvalidArgumentException('Extension admin panel is invalid');
            $key = $panel['key'] ?? null;
            if (!is_string($key) || preg_match('/^[a-z0-9_]{1,32}$/', $key) !== 1 || in_array($key, $keys, true)) throw new \InvalidArgumentException('Extension admin panel key is invalid');
            $keys[] = $key;
            $title = $panel['title'] ?? null;
            if (!is_string($title) || mb_strlen(trim($title)) < 2 || mb_strlen($title) > 64) throw new \InvalidArgumentException('Extension admin panel title is invalid');
            if (!in_array($panel['type'] ?? null, ['stats', 'table'], true)) throw new \InvalidArgumentException('Extension admin panel type is invalid');
            $endpoint = $panel['endpoint'] ?? null;
            if (!is_string($endpoint) || $endpoint === '' || str_starts_with($endpoint, '/') || str_contains($endpoint, '..') || (bool) preg_match('#^[a-z][a-z0-9+.-]*://#i', $endpoint)) throw new \InvalidArgumentException('Extension admin panel endpoint is invalid');
            if (!in_array($panel['permission'] ?? null, $permissions, true)) throw new \InvalidArgumentException('Extension admin panel permission is invalid');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $required = static function (string $key) use ($data): string {
            $value = $data[$key] ?? null;
            if (!is_string($value) || $value === '') throw new \InvalidArgumentException('Extension manifest field is invalid: ' . $key);
            return $value;
        };
        $list = static function (string $key) use ($data): array {
            $value = $data[$key] ?? [];
            if (!is_array($value)) throw new \InvalidArgumentException('Extension manifest field is invalid: ' . $key);
            foreach ($value as $item) if (!is_string($item)) throw new \InvalidArgumentException('Extension manifest field is invalid: ' . $key);
            return array_values($value);
        };
        $requires = $data['requires'] ?? [];
        if (!is_array($requires)) throw new \InvalidArgumentException('Extension manifest field is invalid: requires');
        foreach ($requires as $runtime => $constraint) if (!is_string($runtime) || !is_string($constraint) || $constraint === '') throw new \InvalidArgumentException('Extension manifest field is invalid: requires');
        $admin = $data['admin'] ?? [];
        if (!is_array($admin)) throw new \InvalidArgumentException('Extension manifest field is invalid: admin');
        $adminRow = [];
        foreach ($admin as $key => $value) if (is_string($key)) $adminRow[$key] = $value;
        $license = $data['license'] ?? [];
        if (!is_array($license)) throw new \InvalidArgumentException('Extension manifest field is invalid: license');
        $licenseRow = [];
        foreach ($license as $key => $value) if (is_string($key)) $licenseRow[$key] = $value;
        return new self($required('id'), $required('version'), $requires, $list('capabilities'), $list('permissions'), $list('migrations'), $list('routes'), $adminRow, $licenseRow);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'version' => $this->version, 'requires' => $this->requires, 'capabilities' => $this->capabilities, 'permissions' => $this->permissions, 'migrations' => $this->migrations, 'routes' => $this->routes, 'admin' => $this->admin, 'license' => $this->license];
    }

    /** @return array<string, mixed>|null UI descriptor for enabled extensions, null when the manifest declares no admin block */
    public function adminUi(): ?array
    {
        if ($this->admin === []) return null;
        $panels = [];
        foreach (is_array($this->admin['panels'] ?? null) ? $this->admin['panels'] : [] as $panel) {
            if (!is_array($panel)) continue;
            $row = [];
            foreach ($panel as $key => $value) if (is_string($key)) $row[$key] = $value;
            $panels[] = $row;
        }
        return [
            'label' => (string) ($this->admin['label'] ?? $this->id),
            'section' => (string) ($this->admin['section'] ?? 'Extensions'),
            'href' => '/extensions/' . $this->id,
            'panels' => $panels,
        ];
    }

    public function can(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}
