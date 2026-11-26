<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Platform\Application\AdminOperationsService;
use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use App\Support\ScopeAuthorizer;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class AdminOperationsController extends Controller
{
    public function __construct(private readonly AdminOperationsService $operations) {}

    public function index(Request $request): Response
    {
        try { $scope = $this->scope($request); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); }
        $result = $this->operations->list($scope, $request->queryInt('page', 1), $request->queryInt('per_page', 25), $request->queryString('kind', ''), $request->queryString('state', ''), $request->queryString('q', ''));
        return $this->paginated($result['data'], $result['meta'], 'Operations');
    }

    public function retry(Request $request): Response
    {
        $key = $request->header('Idempotency-Key', '');
        try {
            $scope = $this->scope($request);
            $user = $request->user();
            $actor = is_array($user) ? Arr::str($user, 'email', 'admin') : 'admin';
            $correlation = is_string($request->getAttribute('correlation_id')) ? $request->getAttribute('correlation_id') : $key;
            return $this->success($this->operations->retryJob(Arr::requiredParam($request, 'id'), $scope, is_string($key) ? $key : '', $actor, $correlation), 'Retry queued');
        } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
        catch (\RuntimeException $e) { return $this->error($e->getMessage(), 409, [], 'CONFLICT'); }
    }

    public function replayNotification(Request $request): Response
    {
        $key = $request->header('Idempotency-Key', '');
        try {
            $scope = $this->scope($request);
            $user = $request->user();
            $actor = is_array($user) ? Arr::str($user, 'email', 'admin') : 'admin';
            $correlation = is_string($request->getAttribute('correlation_id')) ? $request->getAttribute('correlation_id') : $key;
            return $this->success($this->operations->replayNotification(Arr::requiredParam($request, 'id'), $scope, is_string($key) ? $key : '', $actor, $correlation), 'Notification replay queued');
        } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
        catch (\RuntimeException $e) { return $this->error($e->getMessage(), 409, [], 'CONFLICT'); }
    }

    private function scope(Request $request): CommerceScope
    {
        $scope = CommerceScope::fromRequest($request);
        $user = $request->user();
        ScopeAuthorizer::assertAllowed($scope, is_array($user) ? $user : []);
        return $scope;
    }
}
