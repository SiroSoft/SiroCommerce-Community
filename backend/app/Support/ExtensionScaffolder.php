<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure filesystem scaffold for extension packages (spec RULE-E1).
 *
 * Generates app/Extensions/<Vendor>/<Name>/ with an ExtensionInterface
 * implementation, a schema-valid manifest, a read-only sample route, a thin
 * controller, and a README. No caller-supplied code is evaluated; only
 * validated PascalCase identifiers and JSON-encoded values reach templates.
 */
final class ExtensionScaffolder
{
    private const SEGMENT_PATTERN = '/^[A-Z][A-Za-z0-9]*$/';
    private const ID_PATTERN = '#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$#';

    public const VERSION = '0.1.0';

    /**
     * @return array{created: list<string>, skipped: list<string>}
     */
    public static function create(string $vendor, string $name, string $root, bool $force = false): array
    {
        self::assertSegment($vendor, 'vendor');
        self::assertSegment($name, 'name');

        $root = rtrim($root, "\\/");
        if ($root === '') {
            throw new \InvalidArgumentException('Extension root path is required');
        }

        $id = strtolower($vendor) . '/' . strtolower($name);
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException('Extension id must be a lowercase vendor/name identifier');
        }

        $target = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Extensions'
            . DIRECTORY_SEPARATOR . $vendor . DIRECTORY_SEPARATOR . $name;
        $files = self::files($vendor, $name, $id);

        if (is_dir($target) && !$force) {
            $skipped = [];
            foreach (array_keys($files) as $file) {
                $skipped[] = $target . DIRECTORY_SEPARATOR . $file;
            }
            return ['created' => [], 'skipped' => $skipped];
        }

        if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
            throw new \RuntimeException('Could not create extension directory: ' . $target);
        }

        $created = [];
        $skipped = [];
        foreach ($files as $file => $contents) {
            $path = $target . DIRECTORY_SEPARATOR . $file;
            if (is_file($path) && !$force) {
                $skipped[] = $path;
                continue;
            }
            if (file_put_contents($path, $contents) === false) {
                throw new \RuntimeException('Could not write extension file: ' . $path);
            }
            $created[] = $path;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    private static function assertSegment(string $value, string $label): void
    {
        if (str_contains($value, '..') || str_contains($value, '/') || str_contains($value, '\\') || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('Extension ' . $label . ' must not contain path traversal characters');
        }
        if (preg_match(self::SEGMENT_PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException('Extension ' . $label . ' must be PascalCase and start with an uppercase letter');
        }
    }

    /** @return array<string, string> */
    private static function files(string $vendor, string $name, string $id): array
    {
        $label = $vendor . ' ' . $name;
        $permission = self::defaultPermission($name);
        $adminLabel = mb_substr($label, 0, 48);

        return [
            'Extension.php' => self::extensionTemplate($vendor, $name, $id, $label, $permission, $adminLabel),
            'manifest.json' => self::manifestTemplate($id, $label, $permission, $adminLabel),
            'routes.php' => self::routesTemplate($vendor, $name, $id),
            'StatusController.php' => self::controllerTemplate($vendor, $name, $id),
            'README.md' => self::readmeTemplate($vendor, $name, $id, $label, $permission),
        ];
    }

    private static function defaultPermission(string $name): string
    {
        return strtolower($name) . ':manage';
    }

    /** @return list<array{key: string, title: string, type: string, endpoint: string, permission: string}> */
    private static function adminPanels(string $permission): array
    {
        return [
            ['key' => 'overview', 'title' => 'Overview', 'type' => 'stats', 'endpoint' => 'admin/overview', 'permission' => $permission],
            ['key' => 'records', 'title' => 'Records', 'type' => 'table', 'endpoint' => 'admin/records', 'permission' => $permission],
        ];
    }

    /** @return array{admin: array{label: string, section: string, panels: list<array<string, string>>}, storefront: list<string>} */
    private static function slotsTemplate(string $adminLabel, string $permission): array
    {
        return [
            'admin' => [
                'label' => $adminLabel,
                'section' => 'Extensions',
                'panels' => self::adminPanels($permission),
            ],
            'storefront' => [],
        ];
    }

    private static function extensionTemplate(string $vendor, string $name, string $id, string $label, string $permission, string $adminLabel): string
    {
        $namespace = 'App\\Extensions\\' . $vendor . '\\' . $name;

        return strtr(<<<'PHP'
<?php

declare(strict_types=1);

namespace {{NAMESPACE}};

use App\Contracts\ExtensionInterface;

/**
 * Scaffolded extension {{ID}}.
 * Generated by `php siro make:extension`; edit freely.
 */
final class Extension implements ExtensionInterface
{
    public static function id(): string
    {
        return {{ID_PHP}};
    }

    public static function compatibleCore(): string
    {
        return '^1.0';
    }

    public static function compatibleCommerce(): string
    {
        return '^0.2';
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'id' => self::id(),
            'name' => {{LABEL_PHP}},
            'version' => {{VERSION_PHP}},
            'requires' => ['core' => self::compatibleCore(), 'commerce' => self::compatibleCommerce()],
            'capabilities' => [],
            'permissions' => [{{PERMISSION_PHP}}],
            'migrations' => [],
            'routes' => ['routes.php'],
            'slots' => [
                'admin' => [
                    'label' => {{ADMIN_LABEL_PHP}},
                    'section' => 'Extensions',
                    'panels' => [
                        ['key' => 'overview', 'title' => 'Overview', 'type' => 'stats', 'endpoint' => 'admin/overview', 'permission' => {{PERMISSION_PHP}}],
                        ['key' => 'records', 'title' => 'Records', 'type' => 'table', 'endpoint' => 'admin/records', 'permission' => {{PERMISSION_PHP}}],
                    ],
                ],
                'storefront' => [],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return [];
    }

    /** @return array<string, \App\Contracts\PaymentGatewayInterface> */
    public function paymentGateways(): array
    {
        return [];
    }

    /** @return array<string, \App\Contracts\ShippingProviderInterface> */
    public function shippingProviders(): array
    {
        return [];
    }

    public function boot(): void
    {
    }

    public function shutdown(): void
    {
    }
}

PHP, [
            '{{NAMESPACE}}' => $namespace,
            '{{ID}}' => $id,
            '{{ID_PHP}}' => var_export($id, true),
            '{{LABEL_PHP}}' => var_export($label, true),
            '{{VERSION_PHP}}' => var_export(self::VERSION, true),
            '{{PERMISSION_PHP}}' => var_export($permission, true),
            '{{ADMIN_LABEL_PHP}}' => var_export($adminLabel, true),
        ]);
    }

    private static function manifestTemplate(string $id, string $label, string $permission, string $adminLabel): string
    {
        $manifest = [
            'id' => $id,
            'name' => $label,
            'version' => self::VERSION,
            'requires' => ['core' => '^1.0', 'commerce' => '^0.2'],
            'capabilities' => [],
            'permissions' => [$permission],
            'migrations' => [],
            'routes' => ['routes.php'],
            'slots' => self::slotsTemplate($adminLabel, $permission),
            'config' => [],
        ];

        // The scaffold declares its own permission so the sample admin panels are
        // schema-valid. Drop both `slots.admin` and the permission when the
        // extension has no admin UI.
        return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    private static function routesTemplate(string $vendor, string $name, string $id): string
    {
        $namespace = 'App\\Extensions\\' . $vendor . '\\' . $name;

        return strtr(<<<'PHP'
<?php

declare(strict_types=1);

/**
 * Mounted by ExtensionRouteLoader under /api/ext/{{ID}}/ (spec RULE-E4).
 * Read-only sample: GET /api/ext/{{ID}}/status
 */

use {{NAMESPACE}}\StatusController;

return [
    ['GET', 'status', [StatusController::class, 'status'], ['auth', 'throttle:60,1']],
];

PHP, [
            '{{NAMESPACE}}' => $namespace,
            '{{ID}}' => $id,
        ]);
    }

    private static function controllerTemplate(string $vendor, string $name, string $id): string
    {
        $namespace = 'App\\Extensions\\' . $vendor . '\\' . $name;

        return strtr(<<<'PHP'
<?php

declare(strict_types=1);

namespace {{NAMESPACE}};

use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/** Read-only sample controller; replace with the extension's real handlers. */
final class StatusController extends Controller
{
    public function status(Request $request): Response
    {
        unset($request);

        return $this->success(['extension' => {{ID_PHP}}, 'status' => 'ok'], 'Extension status');
    }
}

PHP, [
            '{{NAMESPACE}}' => $namespace,
            '{{ID_PHP}}' => var_export($id, true),
        ]);
    }

    private static function readmeTemplate(string $vendor, string $name, string $id, string $label, string $permission): string
    {
        return strtr(<<<'MD'
# {{ID}}

Scaffolded by `php siro make:extension {{VENDOR}} {{NAME}}`.

## Files

- `Extension.php` — lifecycle hooks and `manifest()` (id `{{ID}}`).
- `manifest.json` — contract snapshot; `manifest()` wins when both exist.
- `routes.php` — loader rows for the extension's own routes.
- `StatusController.php` — read-only sample handler.
- `README.md` — this file.

## Enable

```bash
php siro extension:pack app/Extensions/{{VENDOR}}/{{NAME}} --out=dist/{{ID_SLUG}}-{{VERSION}}.zip
php siro extension:verify dist/{{ID_SLUG}}-{{VERSION}}.zip
php siro extension:qit dist/{{ID_SLUG}}-{{VERSION}}.zip
php siro extension:install dist/{{ID_SLUG}}-{{VERSION}}.zip
php siro extension:check
php siro extension:migrate
# Admin -> Extensions -> {{ID}} -> Install -> Enable
```

`extension:verify` and `extension:qit` must pass before install or enable.

## Test locally

The extension loader mounts `routes.php` under `/api/ext/{{ID}}/`:

```bash
curl -H "Authorization: Bearer <token>" \
  http://localhost:8080/api/ext/{{ID}}/status
```

Run the scaffolder unit test from `backend/`:

```bash
php vendor/bin/phpunit tests/Unit/ExtensionScaffolderTest.php
```

## Slots

Slots are declarative extension points; they never inject raw HTML or scripts.

### Admin slots

`slots.admin` declares the nav `label`, `section`, and allowlisted `panels`
(`stats` / `table`). Every panel `permission` must also appear in
`permissions` in both `manifest.json` and `manifest()`, or
`ExtensionManifest` rejects the manifest. The scaffold declares
`{{PERMISSION}}` for this reason.

```json
"slots": {
  "admin": {
    "label": "{{LABEL}}",
    "section": "Extensions",
    "panels": [
      { "key": "overview", "title": "Overview", "type": "stats", "endpoint": "admin/overview", "permission": "{{PERMISSION}}" },
      { "key": "records", "title": "Records", "type": "table", "endpoint": "admin/records", "permission": "{{PERMISSION}}" }
    ]
  },
  "storefront": []
}
```

### Storefront slots

The scaffold ships `"storefront": []` on purpose: no slot is enabled by default.
Add only the slots you actually implement. Valid slot names:

`header`, `footer`, `pdp`, `cart`, `checkout`, `my-account`, `email`, `order-status`.

Storefront slot components must be React/TypeScript components built and
imported into `apps/storefront` (theme sections/components). Do not ship raw
JavaScript, inline `<script>` tags, or unbuilt bundles. The backend stays the
source of truth for prices, totals, and order state.
MD, [
            '{{ID}}' => $id,
            '{{ID_SLUG}}' => str_replace('/', '-', $id),
            '{{VENDOR}}' => $vendor,
            '{{NAME}}' => $name,
            '{{LABEL}}' => $label,
            '{{PERMISSION}}' => $permission,
            '{{VERSION}}' => self::VERSION,
        ]);
    }
}
