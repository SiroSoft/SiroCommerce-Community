<?php
declare(strict_types=1);
namespace App\Modules\Auth\Domain;
final readonly class AuthTokens
{
    /** @param array<string, mixed>|null $user */
    public function __construct(public string $access, public string $refresh, public ?array $user = null) {}
    /** @return array{access: string, refresh: string, token: string, refresh_token: string, token_type: string, expires_in: int, user?: array<string, mixed>} */
    public function toArray(): array { $out = ['access' => $this->access, 'refresh' => $this->refresh, 'token' => $this->access, 'refresh_token' => $this->refresh, 'token_type' => 'Bearer', 'expires_in' => 3600]; if ($this->user !== null) $out['user'] = $this->user; return $out; }
}
