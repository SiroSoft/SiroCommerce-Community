<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

/**
 * Repoints catalog product images from their SVG source at the generated WebP
 * rendition. Only rows whose URL is a safe `/catalog/b2c/<slug>.svg` path are
 * touched; alt text, sort, type, and role are preserved.
 *
 * Idempotent: rows already on a raster rendition are reported and skipped, so a
 * second run performs no writes.
 */
final class CatalogWebpImageAttacher
{
    private const URL_PREFIX = '/catalog/b2c/';
    private const SVG_SUFFIX = '.svg';
    private const WEBP_SUFFIX = '.webp';
    private const RASTER_SUFFIXES = ['.webp', '.avif', '.jpg', '.jpeg', '.png', '.gif'];

    /**
     * @return array{scanned:int,updated:int,would_update:int,already_raster:int,missing_file:int,unsafe_url:int,skipped:int,dry_run:bool}
     */
    public function attach(PDO $pdo, string $publicRoot, bool $dryRun = false, bool $requireFile = true): array
    {
        $root = null;
        if ($requireFile) {
            $resolved = realpath($publicRoot);
            if ($resolved === false || !is_dir($resolved)) {
                throw new RuntimeException('Storefront public directory not found: ' . $publicRoot);
            }
            $root = $resolved;
        }

        $summary = [
            'scanned' => 0,
            'updated' => 0,
            'would_update' => 0,
            'already_raster' => 0,
            'missing_file' => 0,
            'unsafe_url' => 0,
            'skipped' => 0,
            'dry_run' => $dryRun,
        ];

        $statement = $pdo->query(
            "SELECT id, url FROM product_images WHERE url LIKE '/catalog/b2c/%' ORDER BY id"
        );
        if ($statement === false) {
            throw new RuntimeException('Could not read product images.');
        }
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $update = $pdo->prepare('UPDATE product_images SET url = ?, mime_type = ?, updated_at = ? WHERE id = ?');

        $startedTransaction = false;
        if (!$dryRun && !$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        try {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = $row['id'] ?? null;
                $url = $row['url'] ?? null;
                if (!is_string($id) || !is_string($url)) {
                    continue;
                }
                $summary['scanned'] += 1;

                $path = $this->safePath($url);
                if ($path === null) {
                    $summary['unsafe_url'] += 1;
                    continue;
                }

                $lower = strtolower($path);
                if ($this->hasSuffix($lower, self::RASTER_SUFFIXES)) {
                    $summary['already_raster'] += 1;
                    continue;
                }
                if (!str_ends_with($lower, self::SVG_SUFFIX)) {
                    $summary['skipped'] += 1;
                    continue;
                }

                $base = substr($path, 0, -strlen(self::SVG_SUFFIX));
                if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $base) !== 1) {
                    $summary['unsafe_url'] += 1;
                    continue;
                }

                $webpName = $base . self::WEBP_SUFFIX;
                if ($root !== null && $this->webpFilePath($root, $webpName) === null) {
                    $summary['missing_file'] += 1;
                    continue;
                }

                if ($dryRun) {
                    $summary['would_update'] += 1;
                    continue;
                }

                $update->execute([
                    self::URL_PREFIX . $webpName,
                    'image/webp',
                    date('Y-m-d H:i:s'),
                    $id,
                ]);
                $summary['updated'] += 1;
            }

            if ($startedTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction) {
                try {
                    $pdo->rollBack();
                } catch (\PDOException) {
                    // The original failure is the one worth surfacing.
                }
            }
            throw $e;
        }

        return $summary;
    }

    /** @param list<string> $suffixes */
    private function hasSuffix(string $value, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            if (str_ends_with($value, $suffix)) {
                return true;
            }
        }
        return false;
    }

    private function safePath(string $url): ?string
    {
        if (!str_starts_with($url, self::URL_PREFIX)) {
            return null;
        }
        $path = substr($url, strlen(self::URL_PREFIX));
        if ($path === '' || str_contains($path, '/') || str_contains($path, '\\') || str_contains($path, '..')) {
            return null;
        }
        return $path;
    }

    private function webpFilePath(string $root, string $name): ?string
    {
        $relative = 'catalog' . DIRECTORY_SEPARATOR . 'b2c' . DIRECTORY_SEPARATOR . $name;
        $real = realpath($root . DIRECTORY_SEPARATOR . $relative);
        if ($real === false || !is_file($real)) {
            return null;
        }
        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($real, $rootPrefix) ? $real : null;
    }
}
