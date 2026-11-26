<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Auth\Application\AuthService;
use App\Modules\Auth\Application\PermissionResolver;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Self-service profile for the admin console (skeleton API surface).
 */
final class ProfileController extends Controller
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function show(Request $request): Response
    {
        $user = $request->user();
        if (!is_array($user)) return $this->error('Unauthorized', 401);
        return $this->success($this->payload($user), 'Profile');
    }

    public function update(Request $request): Response
    {
        $user = $request->user();
        if (!is_array($user)) return $this->error('Unauthorized', 401);
        /** @var array<string, mixed> $data */
        $data = $request->validate(['name' => 'nullable|string', 'email' => 'nullable|string']);
        $id = Arr::int($user, 'id');
        if (isset($data['name'])) {
            try { $updated = $this->auth->updateProfile($id, Arr::requiredStr($data, 'name')); }
            catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422, ['name' => [$e->getMessage()]]); }
            return $this->success($this->payload($updated), 'Profile updated');
        }
        return $this->success($this->payload($user), 'Profile');
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function payload(array $user): array
    {
        $payload = [];
        foreach (AdminUserController::skeleton($user) as $key => $value) {
            if (is_string($key)) $payload[$key] = $value;
        }
        $payload['permissions'] = PermissionResolver::forRole(Arr::str($user, 'role', 'customer'));

        return $payload;
    }

    public function password(Request $request): Response
    {
        $user = $request->user();
        if (!is_array($user)) return $this->error('Unauthorized', 401);
        /** @var array<string, mixed> $data */
        $data = $request->validate(['current_password' => 'nullable|string', 'password' => 'nullable|string', 'new_password' => 'nullable|string', 'password_confirmation' => 'nullable|string', 'new_password_confirmation' => 'nullable|string']);
        $current = Arr::str($data, 'current_password');
        $new = Arr::str($data, 'new_password') !== '' ? Arr::str($data, 'new_password') : Arr::str($data, 'password');
        if ($current === '' || $new === '') return $this->error('Current and new passwords are required', 422, ['password' => ['Current and new passwords are required']]);
        try { $this->auth->changePassword(Arr::int($user, 'id'), $current, $new); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422, ['password' => [$e->getMessage()]]); }
        return $this->success(null, 'Password changed');
    }
}
