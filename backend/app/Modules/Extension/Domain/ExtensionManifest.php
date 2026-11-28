<?php

declare(strict_types=1);

namespace App\Modules\Extension\Domain;

final readonly class ExtensionManifest
{
    /**
     * Storefront slot names an extension may declare in `manifest.slots.storefront`.
     * Mirror of `SLOT_NAMES` in `apps/storefront/src/slots/types.ts` — keep both
     * lists in sync; the storefront registry test enforces the mapping.
     * @var list<string>
     */
    public const SLOT_NAMES = ['header', 'footer', 'pdp', 'product-card', 'pdp.title', 'pdp.below-atc', 'pdp.below-gallery', 'cart', 'checkout', 'my-account', 'email', 'order-status'];

    /**
     * @param array<string, string> $requires
     * @param list<string> $capabilities
     * @param list<string> $permissions
     * @param list<string> $migrations
     * @param list<string> $routes
     * @param array{label: string, section: string, panels: list<array{key: string, title: string, type: string, endpoint: string, permission: string}>} $admin
     * @param array<string, mixed> $license Supported keys: required (bool), sku (string).
     * @param array<string, mixed> $slots Supported keys: admin (array), storefront (list of SLOT_NAMES).
     * @param list<string> $requiresExtensions Extension ids that must be enabled before this one can be enabled (Batch 1 §2.2 dependency guard).
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
        public array $slots = [],
        public array $requiresExtensions = [],
    ) {
        if ($this->id === '' || $this->version === '') throw new \InvalidArgumentException('Extension id and version are required');
        foreach ([$this->capabilities, $this->permissions, $this->migrations, $this->routes] as $items) {
            foreach ($items as $item) if ($item === '' || trim($item) !== $item) throw new \InvalidArgumentException('Extension manifest lists must contain non-empty strings');
        }
        if ($this->admin !== []) self::validateAdmin($this->admin, $this->permissions);
        if ($this->license !== []) self::validateLicense($this->license);
        if ($this->slots !== []) self::validateSlots($this->slots);
        if ($this->requiresExtensions !== []) self::validateRequiresExtensions($this->requiresExtensions, $this->id);
    }

    /** @param list<mixed> $items */
    private static function validateRequiresExtensions(array $items, string $ownId): void
    {
        $seen = [];
        foreach ($items as $item) {
            if (!is_string($item) || preg_match('/^[a-z0-9][a-z0-9._-]*(\/[a-z0-9][a-z0-9._-]*)?$/', $item) !== 1) throw new \InvalidArgumentException('Extension manifest requires_extensions contains an invalid extension id "' . (is_string($item) ? $item : '') . '"');
            if ($item === $ownId) throw new \InvalidArgumentException('Extension manifest requires_extensions must not list itself');
            if (in_array($item, $seen, true)) throw new \InvalidArgumentException('Extension manifest requires_extensions contains a duplicate "' . $item . '"');
            $seen[] = $item;
        }
    }

    /** @param array<string, mixed> $slots */
    private static function validateSlots(array $slots): void
    {
        if (array_key_exists('admin', $slots) && !is_array($slots['admin'])) throw new \InvalidArgumentException('Extension manifest slots.admin must be an object');
        $storefront = $slots['storefront'] ?? [];
        if (!is_array($storefront)) throw new \InvalidArgumentException('Extension manifest slots.storefront must be an array');
        foreach ($storefront as $slot) {
            if (!is_string($slot) || !in_array($slot, self::SLOT_NAMES, true)) throw new \InvalidArgumentException('Extension manifest slots.storefront contains an unknown slot "' . (is_string($slot) ? $slot : '') . '"');
        }
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
            if (!in_array($panel['type'] ?? null, ['stats', 'table', 'assistant'], true)) throw new \InvalidArgumentException('Extension admin panel type is invalid');
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
        $slots = $data['slots'] ?? [];
        if (!is_array($slots)) throw new \InvalidArgumentException('Extension manifest field is invalid: slots');
        $slotsRow = [];
        foreach ($slots as $key => $value) if (is_string($key)) $slotsRow[$key] = $value;
        return new self($required('id'), $required('version'), $requires, $list('capabilities'), $list('permissions'), $list('migrations'), $list('routes'), $adminRow, $licenseRow, $slotsRow, $list('requires_extensions'));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'version' => $this->version, 'requires' => $this->requires, 'capabilities' => $this->capabilities, 'permissions' => $this->permissions, 'migrations' => $this->migrations, 'routes' => $this->routes, 'admin' => $this->admin, 'license' => $this->license, 'slots' => $this->slots, 'requires_extensions' => $this->requiresExtensions];
    }

    /** @return list<string> storefront slot names declared by this extension */
    public function storefrontSlots(): array
    {
        $slots = $this->slots['storefront'] ?? [];
        if (!is_array($slots)) return [];
        return array_values(array_filter($slots, 'is_string'));
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
