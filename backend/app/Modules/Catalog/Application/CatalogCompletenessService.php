<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Contracts\CatalogCompletenessPort;
use App\Support\Arr;
use App\Support\Ids;
use App\Support\CommerceScope;
use App\Support\ImageDerivativeService;
use Siro\Core\Event;
use Siro\Core\Env;
use Siro\Core\Str;
use Siro\Core\Storage;
use Siro\Core\UploadedFile;

final class CatalogCompletenessService
{
    public function __construct(private readonly CatalogCompletenessPort $catalog, private readonly ?ImageDerivativeService $derivatives = null) {}

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addOption(string $productId, array $data): array
    {
        $name = trim(Arr::requiredStr($data, 'name'));
        if ($name === '') throw new \InvalidArgumentException('Option name is required');
        $option = $this->catalog->addOption($productId, ['id' => Ids::new(), 'name' => $name, 'sort' => Arr::int($data, 'sort')]);
        $this->changed($productId, 'option');
        return $option;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addOptionValue(string $productId, string $optionId, array $data): array
    {
        $value = trim(Arr::requiredStr($data, 'value'));
        if ($value === '') throw new \InvalidArgumentException('Option value is required');
        $surcharge = Arr::int($data, 'surcharge_minor');
        if ($surcharge < 0) throw new \InvalidArgumentException('Option surcharge cannot be negative');
        $result = $this->catalog->addOptionValue($optionId, ['id' => Ids::new(), 'value' => $value, 'sort' => Arr::int($data, 'sort'), 'surcharge_minor' => $surcharge, 'currency' => Arr::str($data, 'currency') ?: null]);
        $this->changed($productId, 'option_value');
        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addMedia(string $productId, array $data): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $productId)) {
            throw new \InvalidArgumentException('Invalid product id');
        }
        $url = trim(Arr::requiredStr($data, 'url'));
        $parsed = parse_url($url);
        if ($url === '' || !is_array($parsed) || !in_array(strtolower((string) ($parsed['scheme'] ?? '')), ['http', 'https'], true) || isset($parsed['user'])) {
            throw new \InvalidArgumentException('Media URL must be an http(s) URL');
        }
        if (preg_match('/(?:javascript|data|<script)/i', $url) === 1) throw new \InvalidArgumentException('Media URL contains forbidden content');
        $host = strtolower((string) ($parsed['host'] ?? ''));
        $isYoutube = $host === 'youtube.com' || $host === 'www.youtube.com' || $host === 'youtu.be' || $host === 'm.youtube.com';
        $isVimeo = $host === 'vimeo.com' || $host === 'www.vimeo.com' || $host === 'player.vimeo.com';
        $path = strtolower((string) ($parsed['path'] ?? ''));
        $isVideoFile = (bool) preg_match('/\.(mp4|webm)$/', $path);
        $requestedType = strtolower(trim(Arr::str($data, 'type')));
        if ($requestedType !== '' && !in_array($requestedType, ['image', 'video', 'youtube'], true)) throw new \InvalidArgumentException('Media type must be image, video or youtube');
        if (($isYoutube || $isVimeo) && $requestedType !== '' && $requestedType !== 'youtube') throw new \InvalidArgumentException('Embed hosts require type youtube');
        if ($requestedType === 'youtube' && !$isYoutube && !$isVimeo) throw new \InvalidArgumentException('Youtube type needs a youtube/vimeo URL');
        if ($requestedType === 'video' && !$isVideoFile && !str_starts_with(strtolower(Arr::str($data, 'mime_type')), 'video/')) throw new \InvalidArgumentException('Video type needs an mp4/webm URL or video MIME');
        $type = $requestedType !== '' ? $requestedType : (($isYoutube || $isVimeo || $isVideoFile || str_starts_with(strtolower(Arr::str($data, 'mime_type')), 'video/')) ? ($isYoutube || $isVimeo ? 'youtube' : 'video') : 'image');
        $optional = array_intersect_key($data, array_flip(['alt', 'title', 'role', 'sort', 'mime_type', 'width', 'height', 'size_bytes', 'checksum']));
        $media = $this->catalog->addMedia($productId, ['id' => Ids::new(), 'type' => $type, 'url' => $url] + $optional);
        $this->changed($productId, 'media');
        return $media;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addUploadedMedia(string $productId, UploadedFile $file, array $data = []): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $productId)) throw new \InvalidArgumentException('Invalid product id');
        if (!$file->isValid()) throw new \InvalidArgumentException('Invalid uploaded file');
        $mime = $file->getMimeType();
        /** @var array<string, array{0: string, 1: int}> $types */
        $types = [
            'image/jpeg' => ['jpeg', self::limit('MEDIA_IMAGE_MAX_BYTES', 10 * 1024 * 1024)],
            'image/png' => ['png', self::limit('MEDIA_IMAGE_MAX_BYTES', 10 * 1024 * 1024)],
            'image/webp' => ['webp', self::limit('MEDIA_IMAGE_MAX_BYTES', 10 * 1024 * 1024)],
            'image/avif' => ['avif', self::limit('MEDIA_IMAGE_MAX_BYTES', 10 * 1024 * 1024)],
            'video/mp4' => ['mp4', self::limit('MEDIA_VIDEO_MAX_BYTES', 100 * 1024 * 1024)],
            'video/webm' => ['webm', self::limit('MEDIA_VIDEO_MAX_BYTES', 100 * 1024 * 1024)],
        ];
        if (!isset($types[$mime])) throw new \InvalidArgumentException('Unsupported media MIME type');
        $size = $file->getSize();
        if ($size < 1 || $size > $types[$mime][1]) throw new \InvalidArgumentException('Media file exceeds its size limit');
        $contents = file_get_contents($file->getPathname());
        if ($contents === false) throw new \InvalidArgumentException('Unable to read uploaded file');
        $type = str_starts_with($mime, 'video/') ? 'video' : 'image';
        $path = 'products/' . $productId . '/' . $type . 's/' . bin2hex(random_bytes(16)) . '.' . $types[$mime][0];
        if (!Storage::put($path, $contents)) throw new \RuntimeException('Unable to store uploaded media');
        $dimensions = str_starts_with($mime, 'image/') ? @getimagesize($file->getPathname()) : false;
        $optional = array_intersect_key($data, array_flip(['alt', 'title', 'role', 'sort']));
        /** @var array<string, mixed> $mediaData */
        $mediaData = [
                'storage_path' => $path, 'url' => Storage::url($path), 'type' => $type, 'mime_type' => $mime,
                'size_bytes' => $size, 'checksum' => $file->hash(),
                'width' => is_array($dimensions) ? (int) $dimensions[0] : null,
                'height' => is_array($dimensions) ? (int) $dimensions[1] : null,
            ] + $optional;
        if ($type === 'image') {
            $derivatives = ($this->derivatives ?? new ImageDerivativeService())->generate($file->getPathname(), $path);
            if ($derivatives !== null) {
                $encoded = json_encode($derivatives['variants'], JSON_UNESCAPED_SLASHES);
                if (is_string($encoded)) $mediaData['variants_json'] = $encoded;
                $mediaData['placeholder'] = $derivatives['placeholder'];
            }
        }
        try {
            return $this->catalog->addMedia($productId, $mediaData);
        } catch (\Throwable $e) {
            Storage::delete($path);
            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    public function removeMedia(string $productId, string $mediaId): ?array
    {
        $media = $this->catalog->removeMedia($productId, $mediaId);
        if ($media !== null) $this->changed($productId, 'media');
        return $media;
    }

    /** @param array<int, string> $mediaIds */
    public function reorderMedia(string $productId, array $mediaIds): void
    {
        $this->catalog->reorderMedia($productId, $mediaIds);
        $this->changed($productId, 'media_order');
    }

    private static function limit(string $key, int $default): int
    {
        $value = (int) (Env::get($key, (string) $default) ?? $default);
        return $value > 0 ? $value : $default;
    }

    /**
     * @param array<int, mixed> $rows
     * @return array{imported: int, errors: array<int, array{row: int, errors: array<int, string>}>}
     */
    public function bulkImport(array $rows, ?CommerceScope $scope = null, bool $validateOnly = false): array
    {
        $imported = 0;
        $errors = [];
        $seenSlugs = [];
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;
            if (!is_array($row)) {
                $errors[] = ['row' => $rowNumber, 'errors' => ['Row must be an object']];
                continue;
            }
            /** @var array<string, mixed> $row */
            $name = trim(Arr::str($row, 'name'));
            $slug = trim(Arr::str($row, 'slug', Str::slug($name)));
            $rowErrors = [];
            if ($name === '') $rowErrors[] = 'name is required';
            if ($slug === '') $rowErrors[] = 'slug is required';
            if (isset($seenSlugs[$slug])) $rowErrors[] = 'slug is duplicated in this import';
            $seenSlugs[$slug] = true;
            if ($this->catalog->productBySlug($slug) !== null) $rowErrors[] = 'slug already exists';
            $price = $row['price_minor'] ?? null;
            $compare = $row['compare_price_minor'] ?? null;
            if ($price !== null && (!is_int($price) || $price < 0)) $rowErrors[] = 'price_minor must be zero or greater';
            if ($compare !== null && (!is_int($compare) || $compare < (int) $price)) $rowErrors[] = 'compare_price_minor must be greater than or equal to price_minor';
            if ($scope !== null && isset($row['currency']) && strtoupper((string) $row['currency']) !== $scope->currency) $rowErrors[] = 'currency must match the active commerce scope';
            if ($rowErrors !== []) {
                $errors[] = ['row' => $rowNumber, 'errors' => $rowErrors];
                continue;
            }
            try {
                if ($validateOnly) { $imported++; continue; }
                $product = $this->catalog->importProduct(['id' => Ids::new(), 'name' => $name, 'slug' => $slug, 'status' => Arr::str($row, 'status', 'draft')] + array_intersect_key($row, array_flip(['type', 'description', 'tags_json'])) + ($scope?->metadata() ?? []));
                $imported++;
                $this->changed(Arr::requiredStr($product, 'id'), 'bulk_import');
            } catch (\Throwable $e) {
                $errors[] = ['row' => $rowNumber, 'errors' => [$e->getMessage()]];
            }
        }
        return ['imported' => $imported, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addVariant(string $productId, array $data): array
    {
        $sku = trim(Arr::requiredStr($data, 'sku'));
        if ($sku === '') throw new \InvalidArgumentException('Variant SKU is required');
        $ids = $data['option_value_ids'] ?? [];
        if (!is_array($ids)) throw new \InvalidArgumentException('option_value_ids must be an array');
        $optionValueIds = array_values(array_filter($ids, static fn (mixed $id): bool => is_string($id)));
        if (count($optionValueIds) !== count(array_unique($optionValueIds))) throw new \InvalidArgumentException('Variant option values must be unique');
        if (!$this->catalog->optionValuesBelongToProduct($productId, $optionValueIds)) throw new \InvalidArgumentException('Variant option value does not belong to product');
        if ($this->catalog->variantCombinationExists($productId, $optionValueIds)) throw new \InvalidArgumentException('Variant combination already exists');
        $variant = $this->catalog->addVariant($productId, ['id' => Ids::new(), 'product_id' => $productId] + $data + ['sku' => $sku, 'currency' => Arr::str($data, 'currency', 'VND')]);
        $this->catalog->assignVariantOptions(Arr::requiredStr($variant, 'id'), $optionValueIds);
        $this->changed($productId, 'variant');
        return $variant;
    }

    private function changed(string $productId, string $reason): void
    {
        Event::emit('catalog.search_index.requested', ['product_id' => $productId, 'reason' => $reason]);
    }
}
