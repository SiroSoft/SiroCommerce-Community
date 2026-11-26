<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Models\MediaAsset;
use App\Support\Arr;
use App\Support\Ids;
use App\Support\ImageDerivativeService;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Storage;

/**
 * Central media library (ảnh / video / file): upload có validate,
 * list/search/filter, xóa. Product media tham chiếu URL từ đây.
 */
final class MediaLibraryController extends Controller
{
    private const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
    private const VIDEO_EXTS = ['mp4', 'webm'];
    private const FILE_EXTS = ['pdf', 'csv', 'zip', 'doc', 'docx', 'txt', 'json', 'xml'];
    private const MAX_IMAGE = 10 * 1024 * 1024;
    private const MAX_VIDEO = 50 * 1024 * 1024;
    private const MAX_FILE = 20 * 1024 * 1024;

    public function index(Request $request): Response
    {
        $q = strtolower(trim((string) $request->query('q', '')));
        $type = strtolower(trim((string) $request->query('type', '')));
        if ($type !== '' && !in_array($type, ['image', 'video', 'file'], true)) return $this->error('Invalid type filter', 422);
        $page = max(1, (int) $request->query('page', '1'));
        $perPage = max(1, min(100, (int) $request->query('per_page', '24')));
        $rows = MediaAsset::query()->orderBy('created_at', 'desc')->get();
        $filtered = [];
        foreach ($rows as $r) {
            $row = Arr::row($r);
            if ($type !== '' && Arr::str($row, 'type') !== $type) continue;
            if ($q !== '' && stripos(Arr::str($row, 'title') . ' ' . Arr::str($row, 'alt') . ' ' . Arr::str($row, 'url'), $q) === false) continue;
            $filtered[] = $this->present($row);
        }
        $total = count($filtered);
        $slice = array_slice($filtered, ($page - 1) * $perPage, $perPage);
        return $this->success(['data' => $slice, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage]], 'Media library');
    }

    public function store(Request $request): Response
    {
        $key = $request->header('Idempotency-Key', '');
        if (!is_string($key) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) return $this->error('A valid Idempotency-Key is required', 422);
        $file = $request->file('file');
        if ($file === null) return $this->error('A file is required', 422, ['file' => ['A file is required']]);
        if (!$file->isValid()) return $this->error('Upload failed', 422, ['file' => ['Invalid upload']]);
        $ext = strtolower($file->getClientOriginalExtension());
        // svg excluded: script vector; core allowlist is broader than what we serve.
        if (in_array($ext, self::IMAGE_EXTS, true)) {
            $type = 'image';
            $max = self::MAX_IMAGE;
            $mimeFamily = 'image/';
        } elseif (in_array($ext, self::VIDEO_EXTS, true)) {
            $type = 'video';
            $max = self::MAX_VIDEO;
            $mimeFamily = 'video/';
        } elseif (in_array($ext, self::FILE_EXTS, true)) {
            $type = 'file';
            $max = self::MAX_FILE;
            $mimeFamily = '';
        } else {
            return $this->error('File type not allowed (images, mp4/webm video, documents)', 422, ['file' => ['Invalid type']]);
        }
        $size = $file->getSize();
        if ($size <= 0 || $size > $max) return $this->error('File is too large (max ' . (int) ($max / 1024 / 1024) . 'MB)', 422, ['file' => ['Too large']]);
        $mime = '';
        try {
            $mime = (string) $file->getMimeType();
        } catch (\Throwable $e) {
        }
        if ($mimeFamily !== '' && $mime !== '' && !str_starts_with(strtolower($mime), $mimeFamily)) {
            return $this->error('File content does not match its extension', 422, ['file' => ['Mismatched content']]);
        }
        if (preg_match('/(?:<script|javascript:)/i', $file->getClientOriginalName()) === 1) return $this->error('Invalid filename', 422);
        $data = $request->validate(['title' => 'nullable|string', 'alt' => 'nullable|string']);
        try {
            if ($type === 'video' || $ext === 'avif') {
                // Core uploader allowlist has no video or AVIF: persist bytes
                // ourselves after the controller-level validation above.
                $tmp = $file->getPathname();
                $content = @file_get_contents($tmp);
                if ($content === false) return $this->error('Upload failed', 422);
                $name = bin2hex(random_bytes(8)) . '.' . $ext;
                $path = 'uploads/' . $name;
                if (!Storage::put($path, $content)) return $this->error('Upload failed', 422);
                $url = '/storage/' . $path;
                $storagePath = $path;
            } else {
                $url = $file->store('uploads', null, true);
                $storagePath = ltrim(preg_replace('~^/storage/~', '', $url) ?? '', '/');
            }
        } catch (\Throwable $e) {
            return $this->error('Upload failed', 422, ['file' => ['Upload failed']]);
        }
        $derivatives = $type === 'image' ? (new ImageDerivativeService())->generate($file->getPathname(), $storagePath) : null;
        $encodedVariants = $derivatives !== null ? json_encode($derivatives['variants'], JSON_UNESCAPED_SLASHES) : false;
        $user = $request->user();
        $payload = [
            'id' => Ids::new(),
            'url' => $url,
            'storage_path' => $storagePath,
            'type' => $type,
            'mime_type' => $mime !== '' ? $mime : null,
            'size_bytes' => $size,
            'width' => null,
            'height' => null,
            'title' => trim(Arr::str($data, 'title', '')) !== '' ? mb_substr(trim(Arr::str($data, 'title')), 0, 200) : null,
            'alt' => trim(Arr::str($data, 'alt', '')) !== '' ? mb_substr(trim(Arr::str($data, 'alt')), 0, 200) : null,
            'created_by' => is_array($user) ? Arr::str($user, 'id', null) : null,
            'created_at' => date('Y-m-d H:i:s'),
            'variants_json' => is_string($encodedVariants) ? $encodedVariants : null,
            'placeholder' => $derivatives['placeholder'] ?? null,
        ];
        \Siro\Core\Database::table('media_assets')->insert($payload);
        $row = MediaAsset::query()->where('id', $payload['id'])->first();
        return $this->created($row ? $this->present(Arr::row($row)) : $payload, 'Asset uploaded');
    }

    public function destroy(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        $row = MediaAsset::query()->where('id', $id)->first();
        if ($row === null) return $this->error('Asset not found', 404);
        $data = Arr::row($row);
        $storagePath = Arr::str($data, 'storage_path');
        try {
            if ($storagePath !== '' && Storage::exists($storagePath) && !Storage::delete($storagePath)) {
                throw new \RuntimeException('Unable to remove file');
            }
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 503);
        }
        $row->delete();
        return $this->success(null, 'Asset deleted');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $raw = $row['variants_json'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && $decoded !== []) $row['variants'] = $decoded;
        } elseif (is_array($raw) && $raw !== []) {
            $row['variants'] = $raw;
        }
        $url = $row['url'] ?? null;
        if (is_string($url)) {
            $row['url'] = ImageDerivativeService::publicUrl($url);
        }
        $variants = $row['variants'] ?? null;
        if (is_array($variants)) {
            foreach ($variants as $index => $variant) {
                if (!is_array($variant)) continue;
                $variantUrl = $variant['url'] ?? null;
                if (!is_string($variantUrl)) continue;
                $variant['url'] = ImageDerivativeService::publicUrl($variantUrl);
                $variants[$index] = $variant;
            }
            $row['variants'] = $variants;
        }
        return $row;
    }
}
