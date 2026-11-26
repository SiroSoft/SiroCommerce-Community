<?php
declare(strict_types=1);
namespace App\Modules\Seo\Application;
use App\Modules\Seo\Contracts\SeoStore;
final class SeoService
{
    public function __construct(private readonly SeoStore $store) {}
    /** @return array<string, mixed>|null */ public function meta(string $type, string $id): ?array { return $this->store->meta($type, $id); }
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function saveMeta(string $type, string $id, array $data): array { return $this->store->saveMeta($type, $id, $data); }
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function saveRedirect(array $data): array
    {
        $from = is_string($data['from_path'] ?? null) ? $data['from_path'] : '';
        $to = is_string($data['to_path'] ?? null) ? $data['to_path'] : '';
        if (!preg_match('~^/[A-Za-z0-9/_-]*(?:\?[A-Za-z0-9_=&-]*)?$~', $from) || !preg_match('~^/[A-Za-z0-9/_-]*(?:\?[A-Za-z0-9_=&-]*)?$~', $to)) {
            throw new \InvalidArgumentException('Redirect paths must be local absolute paths.');
        }
        $rawCode = $data['code'] ?? 301;
        $code = is_int($rawCode) ? $rawCode : (is_string($rawCode) && ctype_digit($rawCode) ? (int) $rawCode : 0);
        if (!in_array($code, [301, 302, 307, 308], true)) throw new \InvalidArgumentException('Invalid redirect status code.');
        return $this->store->saveRedirect(['from_path' => $from, 'to_path' => $to, 'code' => $code]);
    }
    /** @return array<int, array{loc: string, lastmod: string}> */ public function sitemapUrls(string $base): array { return $this->store->sitemap($base); }
    /** @return array<int, array{type: string, entity: string, impact: string, fix: string}> */ public function health(): array { return $this->store->health(); }
}
