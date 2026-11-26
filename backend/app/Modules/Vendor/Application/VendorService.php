<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Application;

use App\Modules\Vendor\Domain\VendorConflict;
use App\Repositories\VendorRepository;
use App\Support\Arr;
use App\Support\CommerceScope;
use Siro\Core\Audit;
use Siro\Core\Str;

final class VendorService
{
    public function __construct(private readonly VendorRepository $vendors)
    {
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function list(?string $status, string $q, int $page, int $perPage): array
    {
        $status = strtolower(trim($status ?? ''));
        if ($status !== '' && !in_array($status, ['active', 'suspended'], true)) {
            throw new \InvalidArgumentException('status must be active or suspended');
        }
        return $this->vendors->paginate($status === '' ? null : $status, $q, $page, $perPage);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $name = trim(Arr::str($data, 'name'));
        if ($name === '') throw new \InvalidArgumentException('Vendor name is required');
        $slug = $this->normalizeSlug(Arr::str($data, 'slug'), $name);
        if ($this->vendors->slugExists($slug)) throw new VendorConflict('Vendor slug is already taken');
        $row = $this->vendors->create([
            'id' => \App\Support\Ids::new(),
            'name' => $name,
            'slug' => $slug,
            'status' => $this->normalizeStatus(Arr::str($data, 'status', 'active')),
            'commission_bps' => $this->normalizeCommission($data['commission_bps'] ?? 0),
            'contact_email' => $this->normalizeEmail($data['contact_email'] ?? null),
            'description' => $this->normalizeDescription($data['description'] ?? null),
            'owner_customer_id' => $this->normalizeOwnerCustomerId($data['owner_customer_id'] ?? null),
        ]);
        $row['products_count'] = 0;
        $this->vendors->invalidateCatalogCaches();
        Audit::log('commerce.vendor.created', ['vendor_id' => Arr::str($row, 'id'), 'slug' => $slug]);
        return $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $data): ?array
    {
        $existing = $this->vendors->find($id);
        if ($existing === null) return null;
        $changes = [];
        if (array_key_exists('name', $data)) {
            $name = trim(Arr::str($data, 'name'));
            if ($name === '') throw new \InvalidArgumentException('Vendor name is required');
            $changes['name'] = $name;
        }
        if (array_key_exists('slug', $data)) {
            $slug = $this->normalizeSlug(Arr::str($data, 'slug'), Arr::str($changes, 'name', Arr::str($existing, 'name')));
            if ($this->vendors->slugExists($slug, $id)) throw new VendorConflict('Vendor slug is already taken');
            $changes['slug'] = $slug;
        }
        if (array_key_exists('status', $data)) $changes['status'] = $this->normalizeStatus(Arr::str($data, 'status'));
        if (array_key_exists('commission_bps', $data)) $changes['commission_bps'] = $this->normalizeCommission($data['commission_bps']);
        if (array_key_exists('contact_email', $data)) $changes['contact_email'] = $this->normalizeEmail($data['contact_email']);
        if (array_key_exists('description', $data)) $changes['description'] = $this->normalizeDescription($data['description']);
        if (array_key_exists('owner_customer_id', $data)) $changes['owner_customer_id'] = $this->normalizeOwnerCustomerId($data['owner_customer_id']);
        if ($changes === []) {
            $row = $this->withCount($existing);
            return $row;
        }
        $row = $this->vendors->update($id, $changes);
        if ($row === null) return null;
        $row = $this->withCount($row);
        $this->vendors->invalidateCatalogCaches();
        Audit::log('commerce.vendor.updated', ['vendor_id' => $id, 'fields' => array_keys($changes)]);
        return $row;
    }

    public function delete(string $id): bool
    {
        if ($this->vendors->find($id) === null) return false;
        if ($this->vendors->hasProducts($id)) throw new VendorConflict('Vendor still has products assigned');
        $deleted = $this->vendors->delete($id);
        if ($deleted) {
            $this->vendors->invalidateCatalogCaches();
            Audit::log('commerce.vendor.deleted', ['vendor_id' => $id]);
        }
        return $deleted;
    }

    /** @return array<string, mixed>|null */
    public function publicShow(string $slug, ?CommerceScope $scope = null): ?array
    {
        $vendor = $this->vendors->findBySlug($slug);
        if ($vendor === null) return null;
        $status = Arr::str($vendor, 'status', 'active');
        $suspended = $status !== 'active';
        $id = Arr::str($vendor, 'id');
        return [
            'id' => $id,
            'name' => Arr::str($vendor, 'name'),
            'slug' => Arr::str($vendor, 'slug'),
            'status' => $status,
            'suspended' => $suspended,
            'description' => Arr::str($vendor, 'description'),
            'contact_email' => Arr::str($vendor, 'contact_email') !== '' ? Arr::str($vendor, 'contact_email') : null,
            'product_count' => $suspended ? 0 : $this->vendors->productCount($id, $scope),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withCount(array $row): array
    {
        $row['products_count'] = $this->vendors->productCount(Arr::str($row, 'id'));
        return $row;
    }

    private function normalizeSlug(string $value, string $name): string
    {
        $slug = strtolower(trim($value));
        if ($slug === '') $slug = strtolower(trim(Str::slug($name)));
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $slug) !== 1) {
            throw new \InvalidArgumentException('Vendor slug must be lowercase, path-safe, and at most 80 characters');
        }
        return $slug;
    }

    private function normalizeStatus(string $value): string
    {
        $status = strtolower(trim($value));
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new \InvalidArgumentException('status must be active or suspended');
        }
        return $status;
    }

    private function normalizeCommission(mixed $value): int
    {
        if ($value === null || $value === '') return 0;
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) $value = (int) trim($value);
        if (!is_int($value) || $value < 0 || $value > 10000) {
            throw new \InvalidArgumentException('commission_bps must be an integer between 0 and 10000');
        }
        return $value;
    }

    private function normalizeEmail(mixed $value): ?string
    {
        if ($value === null) return null;
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            throw new \InvalidArgumentException('contact_email must be a valid email address');
        }
        $email = trim((string) $value);
        if ($email === '') return null;
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('contact_email must be a valid email address');
        }
        return $email;
    }

    private function normalizeDescription(mixed $value): ?string
    {
        if ($value === null) return null;
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            throw new \InvalidArgumentException('description must be a string');
        }
        return trim((string) $value);
    }

    private function normalizeOwnerCustomerId(mixed $value): ?string
    {
        if ($value === null) return null;
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            throw new \InvalidArgumentException('owner_customer_id must be a string');
        }
        $id = trim((string) $value);
        return $id === '' ? null : $id;
    }
}
