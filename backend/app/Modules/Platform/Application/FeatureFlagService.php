<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application;

use Siro\Core\Env;

/**
 * Edition feature flags for the Community/Core, Pro and Enterprise packaging.
 *
 * Source of truth is environment configuration:
 *   - PRO_EDITION: community|pro|enterprise (unknown values fail closed to community)
 *   - PRO_FEATURES: comma-separated allowlist of the fixed flag keys below
 *
 * Community always returns every flag false. Pro/Enterprise only enable keys
 * that are both known and explicitly allowlisted. Unknown keys never throw;
 * they simply return false so a typo cannot accidentally unlock a feature.
 */
final class FeatureFlagService
{
    public const EDITION_COMMUNITY = 'community';
    public const EDITION_PRO = 'pro';
    public const EDITION_ENTERPRISE = 'enterprise';

    /** @var list<string> */
    public const FLAGS = [
        'multi_store_theming',
        'marketplace',
        'sso',
        'enterprise_audit',
        'ai_managed',
        'white_label',
    ];

    /** @var list<string> */
    private const EDITIONS = [
        self::EDITION_COMMUNITY,
        self::EDITION_PRO,
        self::EDITION_ENTERPRISE,
    ];

    private string $edition;

    /** @var array<string, true> */
    private array $allowlist;

    public function __construct(?string $edition = null, ?string $features = null)
    {
        $edition ??= Env::get('PRO_EDITION', self::EDITION_COMMUNITY) ?? self::EDITION_COMMUNITY;
        $features ??= Env::get('PRO_FEATURES', '') ?? '';

        $normalized = strtolower(trim($edition));
        $this->edition = in_array($normalized, self::EDITIONS, true) ? $normalized : self::EDITION_COMMUNITY;

        $keys = [];
        foreach (explode(',', strtolower($features)) as $key) {
            $key = trim($key);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }
        $this->allowlist = $keys;
    }

    public function edition(): string
    {
        return $this->edition;
    }

    /**
     * Unknown and community-disabled flags always return false; this method is
     * safe to call from controllers, middleware and background jobs.
     */
    public function enabled(string $flag): bool
    {
        if ($this->edition === self::EDITION_COMMUNITY) {
            return false;
        }

        $flag = strtolower(trim($flag));

        return in_array($flag, self::FLAGS, true) && isset($this->allowlist[$flag]);
    }

    /**
     * Stable, secret-free payload for the admin endpoint.
     *
     * @return array{edition: string, flags: array<string, bool>}
     */
    public function all(): array
    {
        $flags = [];
        foreach (self::FLAGS as $flag) {
            $flags[$flag] = $this->enabled($flag);
        }

        return ['edition' => $this->edition, 'flags' => $flags];
    }
}
