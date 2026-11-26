<?php

declare(strict_types=1);

namespace App\Extensions\Siro\SocialShare;

use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/** Thin HTTP layer: validate -> service. Served under /api/ext/siro/social-share/. */
final class SocialShareController extends Controller
{
    public function __construct(private readonly SocialShareService $service = new SocialShareService())
    {
    }

    public function settings(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        $settings = $this->service->settings($scope);
        return $this->success($settings + ['table' => $this->table($settings)], 'Social share settings');
    }

    public function update(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'enabled' => 'nullable',
            'networks' => 'nullable|array',
            'label' => 'nullable|string|max:60',
        ]);
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        try {
            return $this->success($this->service->save($scope, $data), 'Social share settings saved');
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 409);
        }
    }

    public function publicSettings(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        return $this->success($this->service->publicData($scope), 'Social share');
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{columns: list<array{key: string, label: string}>, rows: list<array<string, string>>}
     */
    private function table(array $settings): array
    {
        $columns = [
            ['key' => 'enabled', 'label' => 'Enabled'],
            ['key' => 'networks', 'label' => 'Networks'],
            ['key' => 'label', 'label' => 'Label'],
        ];
        $networks = is_array($settings['networks'] ?? null) ? $settings['networks'] : [];
        $names = [];
        foreach ($networks as $network) {
            if (is_string($network)) {
                $names[] = $network;
            }
        }
        $row = [
            'enabled' => ($settings['enabled'] ?? false) === true ? 'Enabled' : 'Disabled',
            'networks' => implode(', ', $names),
            'label' => is_string($settings['label'] ?? null) ? $settings['label'] : '',
        ];
        return ['columns' => $columns, 'rows' => [$row]];
    }
}
