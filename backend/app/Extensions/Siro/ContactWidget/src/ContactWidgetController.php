<?php

declare(strict_types=1);

namespace App\Extensions\Siro\ContactWidget;

use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/** Thin HTTP layer: validate -> service. Served under /api/ext/siro/contact-widget/. */
final class ContactWidgetController extends Controller
{
    public function __construct(private readonly ContactWidgetService $service = new ContactWidgetService())
    {
    }

    /**
     * Admin settings plus the read-only panel stats expected by the manifest
     * admin block (spec EXTENSION_UI_SPEC).
     */
    public function settings(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }

        $data = $this->service->settings($scope);
        $links = 0;
        foreach (['zalo_url', 'whatsapp_url', 'email'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $links++;
            }
        }
        $data['stats'] = [
            ['label' => 'Status', 'value' => ($data['enabled'] ?? false) === true ? 'Enabled' : 'Disabled'],
            ['label' => 'Contact links', 'value' => (string) $links],
            ['label' => 'Label', 'value' => is_string($data['label'] ?? null) ? $data['label'] : ''],
        ];

        return $this->success($data, 'Contact widget settings');
    }

    public function save(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'enabled' => 'nullable',
            'zalo_url' => 'nullable|string|max:2048',
            'whatsapp_url' => 'nullable|string|max:2048',
            'email' => 'nullable|string|max:254',
            'label' => 'nullable|string',
        ]);
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        try {
            return $this->success($this->service->save($scope, $data), 'Contact widget settings saved');
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 409);
        }
    }

    /** Public, unauthenticated storefront payload (enabled + links + label). */
    public function publicData(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        return $this->success($this->service->publicData($scope), 'Contact widget');
    }
}
