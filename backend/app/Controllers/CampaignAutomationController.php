<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Platform\Application\CampaignAutomationService;
use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use App\Support\ScopeAuthorizer;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class CampaignAutomationController extends Controller
{
    public function __construct(private readonly CampaignAutomationService $service) {}
    public function show(Request $request): Response { try { return $this->success($this->service->get($this->scope($request)), 'Campaign automation settings'); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); } }
    public function update(Request $request): Response
    {
        try {
            $scope = $this->scope($request);
            $data = $request->validate(['enabled' => 'nullable|boolean', 'max_messages_per_day' => 'nullable|integer', 'quiet_hours_start' => 'nullable|integer', 'quiet_hours_end' => 'nullable|integer', 'allowed_channels' => 'nullable|array']);
            $user = $request->user();
            return $this->success($this->service->update($scope, $data, is_array($user) ? Arr::str($user, 'email', 'admin') : 'admin'), 'Campaign automation settings updated');
        } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
    }
    private function scope(Request $request): CommerceScope { $scope = CommerceScope::fromRequest($request); $user = $request->user(); ScopeAuthorizer::assertAllowed($scope, is_array($user) ? $user : []); return $scope; }
}
