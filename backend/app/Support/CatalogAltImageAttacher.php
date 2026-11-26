<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

/**
 * Adds a generated `<slug>-alt.svg` gallery frame to every active product that
 * still only advertises the seeded `<slug>-front.svg` frame.
 */
final class CatalogAltImageAttacher
{
    private const URL_PREFIX = '/catalog/b2c/';
    private const FRONT_SUFFIX = '-front.svg';
    private const ALT_SUFFIX = '-alt.svg';

    /**
     * @return array{scanned:int,inserted:int,would_insert:int,existing:int,missing_file:int,unsafe_url:int,dry_run:bool}
     */
    public function attach(PDO $pdo, string $publicRoot, bool $dryRun = false, bool $requireFile = true): array
    {
        $root = $requireFile ? realpath($publicRoot) : null;
        if ($requireFile && ($root === false || !is_dir($root))) {
            throw new RuntimeException('Storefront public directory not found: ' . $publicRoot);
        }
        if ($root === false) $root = null;

        $summary = [
            'scanned' => 0,
            'inserted' => 0,
            'would_insert' => 0,
            'existing' => 0,
            'missing_file' => 0,
            'unsafe_url' => 0,
            'dry_run' => $dryRun,
        ];

        $rows = $this->frontImageRows($pdo);
        $seen = [];
        $exists = $pdo->prepare('SELECT 1 FROM product_images WHERE product_id = ? AND url = ? LIMIT 1');
        $maxSort = $pdo->prepare('SELECT COALESCE(MAX(sort), -1) FROM product_images WHERE product_id = ?');
        $insert = $pdo->prepare(
            'INSERT INTO product_images (id, product_id, url, alt, sort, type, mime_type, role, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $startedTransaction = false;
        if (!$dryRun && !$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        try {
            foreach ($rows as $row) {
                $productId = $row['product_id'];
                if (isset($seen[$productId])) {
                    continue;
                }
                $seen[$productId] = true;
                $summary['scanned'] += 1;

                $slug = $this->slugFromFrontUrl($row['url']);
                if ($slug === null) {
                    $summary['unsafe_url'] += 1;
                    continue;
                }

                $altUrl = self::URL_PREFIX . $slug . self::ALT_SUFFIX;
                $exists->execute([$productId, $altUrl]);
                if ($exists->fetchColumn() !== false) {
                    $summary['existing'] += 1;
                    continue;
                }

                if ($root !== null && $this->altFilePath($root, $slug) === null) {
                    $summary['missing_file'] += 1;
                    continue;
                }

                if ($dryRun) {
                    $summary['would_insert'] += 1;
                    continue;
                }

                $maxSort->execute([$productId]);
                $sort = (int) $maxSort->fetchColumn() + 1;
                $now = date('Y-m-d H:i:s');
                $insert->execute([
                    $this->imageId($pdo, $productId, $slug),
                    $productId,
                    $altUrl,
                    $row['name'] . ' alternate view',
                    $sort,
                    'image',
                    'image/svg+xml',
                    'gallery',
                    $now,
                    $now,
                ]);
                $summary['inserted'] += 1;
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

    /** @return list<array{product_id:string,name:string,url:string}> */
    private function frontImageRows(PDO $pdo): array
    {
        $statement = $pdo->query(
            "SELECT p.id AS product_id, p.name AS name, i.url AS url "
            . 'FROM products p INNER JOIN product_images i ON i.product_id = p.id '
            . "WHERE p.status = 'active' AND i.url LIKE '/catalog/b2c/%-front.svg' "
            . 'ORDER BY p.id, i.sort, i.id'
        );
        if ($statement === false) {
            throw new RuntimeException('Could not read product images.');
        }

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $productId = $row['product_id'] ?? null;
            $name = $row['name'] ?? null;
            $url = $row['url'] ?? null;
            if (!is_string($productId) || !is_string($name) || !is_string($url)) {
                continue;
            }
            $rows[] = ['product_id' => $productId, 'name' => $name, 'url' => $url];
        }
        return $rows;
    }

    private function slugFromFrontUrl(string $url): ?string
    {
        if (!str_starts_with($url, self::URL_PREFIX) || !str_ends_with($url, self::FRONT_SUFFIX)) {
            return null;
        }
        $path = substr($url, strlen(self::URL_PREFIX));
        if ($path === '' || str_contains($path, '/') || str_contains($path, '\\') || str_contains($path, '..')) {
            return null;
        }
        $slug = substr($path, 0, -strlen(self::FRONT_SUFFIX));
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1 ? $slug : null;
    }

    private function altFilePath(string $root, string $slug): ?string
    {
        $relative = 'catalog' . DIRECTORY_SEPARATOR . 'b2c' . DIRECTORY_SEPARATOR . $slug . self::ALT_SUFFIX;
        $real = realpath($root . DIRECTORY_SEPARATOR . $relative);
        if ($real === false || !is_file($real)) {
            return null;
        }
        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($real, $rootPrefix) ? $real : null;
    }

    private function imageId(PDO $pdo, string $productId, string $slug): string
    {
        $candidate = $productId . '-img-alt';
        if (strlen($candidate) > 26) {
            $candidate = 'alt-' . substr(hash('sha256', $productId . '|' . $slug), 0, 22);
        }
        $check = $pdo->prepare('SELECT product_id FROM product_images WHERE id = ?');
        $check->execute([$candidate]);
        $owner = $check->fetchColumn();
        if ($owner === false || $owner === $productId) {
            return $candidate;
        }
        return 'alt-' . substr(hash('sha256', $productId . '|' . $slug . '|' . $candidate), 0, 22);
    }
}
