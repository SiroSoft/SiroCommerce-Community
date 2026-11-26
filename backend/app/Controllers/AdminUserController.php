<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use App\Modules\Auth\Application\AuthService;
use App\Role;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Merchant user directory (read-only).
 * Password hashes stay hidden via User::$hidden.
 */
final class AdminUserController extends Controller
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function index(Request $request): Response
    {
        $page = max($request->queryInt('page', 1), 1);
        $perPage = min(max($request->queryInt('per_page', 20), 1), 100);
        $query = User::query();
        $search = trim($request->queryString('q', '') ?: $request->queryString('search', ''));
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $query->whereRaw('(email LIKE ? OR name LIKE ?)', [$like, $like]);
        }
        $role = trim($request->queryString('role', ''));
        if ($role !== '') $query->where('role', $role);
        $status = trim($request->queryString('status', ''));
        if ($status !== '') $query->where('status', $status);
        $total = $query->count();
        $rows = $query->orderBy('id', 'asc')->limit($perPage)->offset(($page - 1) * $perPage)->get();
        $data = array_map(static fn ($model): array => $model->toArray(), $rows);
        return $this->paginated($data, ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => (int) max(1, ceil($total / $perPage))], 'Users list');
    }

    public function show(Request $request): Response
    {
        $id = (int) Arr::requiredParam($request, 'id');
        $row = User::find($id);
        if ($row === null) return $this->error('User not found', 404);
        return $this->success(self::skeleton($row->toArray()), 'User detail');
    }

    public function store(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => 'required|string', 'email' => 'required|string', 'password' => 'required|string',
            'role' => 'nullable|string', 'status' => 'nullable|string',
        ]);
        if (!filter_var(trim(Arr::requiredStr($data, 'email')), FILTER_VALIDATE_EMAIL)) return $this->error('Email is invalid', 422, ['email' => ['Must be a valid email address']]);
        if (strlen(Arr::requiredStr($data, 'password')) < 8) return $this->error('Password must be at least 8 characters', 422, ['password' => ['Minimum length is 8']]);
        $role = Arr::str($data, 'role', 'customer');
        if (!in_array($role, [...Role::all(), 'customer'], true)) $role = 'customer';
        try {
            $user = $this->auth->register(['name' => trim(Arr::requiredStr($data, 'name')), 'email' => strtolower(trim(Arr::requiredStr($data, 'email'))), 'password' => Arr::requiredStr($data, 'password'), 'role' => $role === 'customer' ? Role::VIEWER : $role]);
            if ($role === 'customer') {
                $stored = User::query()->where('email', strtolower(trim(Arr::requiredStr($data, 'email'))))->first();
                if ($stored !== null) { $stored->update(['role' => 'customer']); $user = $stored->toArray(); }
            }
        } catch (\RuntimeException $e) { return $this->error($e->getMessage(), 422, ['email' => [$e->getMessage()]]); }
        return $this->created(self::skeleton($user), 'User created');
    }

    public function update(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => 'nullable|string', 'email' => 'nullable|string', 'role' => 'nullable|string', 'status' => 'nullable|string',
        ]);
        $id = (int) Arr::requiredParam($request, 'id');
        $row = User::find($id);
        if ($row === null) return $this->error('User not found', 404);
        $patch = [];
        if (isset($data['name']) && trim((string) $data['name']) !== '') $patch['name'] = trim((string) $data['name']);
        if (isset($data['email']) && trim((string) $data['email']) !== '') {
            $email = strtolower(trim((string) $data['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return $this->error('Email is invalid', 422, ['email' => ['Must be a valid email address']]);
            $taken = User::query()->where('email', $email)->first();
            if ($taken !== null && (int) $taken->getAttribute('id') !== $id) return $this->error('Email already taken', 422, ['email' => ['Email already taken']]);
            $patch['email'] = $email;
        }
        if (isset($data['role']) && in_array($data['role'], [...Role::all(), 'customer'], true)) $patch['role'] = $data['role'];
        if (isset($data['status']) && in_array($data['status'], ['active', 'suspended', 'disabled'], true)) $patch['status'] = $data['status'];
        if ($patch !== []) $row->update($patch);
        $fresh = User::find($id);
        return $this->success(self::skeleton($fresh === null ? [] : $fresh->toArray()), 'User updated');
    }

    public function destroy(Request $request): Response
    {
        $id = (int) Arr::requiredParam($request, 'id');
        $row = User::find($id);
        if ($row === null) return $this->error('User not found', 404);
        $actor = $request->user();
        if (is_array($actor) && (int) ($actor['id'] ?? 0) === $id) return $this->error('You cannot delete your own account', 422);
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM refresh_tokens WHERE user_id=?')->execute([$id]);
        $row->delete();
        return $this->noContent();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function skeleton(array $row): array
    {
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'name' => Arr::str($row, 'name'), 'email' => Arr::str($row, 'email'),
            'role' => Arr::str($row, 'role', 'customer'), 'status' => Arr::str($row, 'status', 'active'),
            'avatar' => null, 'phone' => null,
            'email_verified_at' => is_string($row['email_verified_at'] ?? null) ? substr($row['email_verified_at'], 0, 19) : null,
            'created_at' => self::date($row['created_at'] ?? null), 'updated_at' => self::date($row['updated_at'] ?? null),
        ];
    }

    private static function date(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') return $value;
        if (is_array($value) && is_string($value['date'] ?? null)) return substr($value['date'], 0, 19);
        return null;
    }
}
