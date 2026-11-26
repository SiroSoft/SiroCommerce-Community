<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Platform\Application\AdminSubscriptionService;
use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use App\Support\ScopeAuthorizer;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class AdminSubscriptionController extends Controller
{
    public function __construct(private readonly AdminSubscriptionService $subscriptions) {}
    public function index(Request $request): Response
    { try { $result = $this->subscriptions->list($this->scope($request), $request->queryInt('page', 1), $request->queryInt('per_page', 25), $request->queryString('state', ''), $request->queryString('q', '')); return $this->paginated($result['data'], $result['meta'], 'Subscriptions'); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); } }
    public function show(Request $request): Response
    { try { $result = $this->subscriptions->detail(Arr::requiredParam($request, 'id'), $this->scope($request)); return $result === null ? $this->error('Subscription not found', 404) : $this->success($result, 'Subscription'); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); } }
    public function summary(Request $request): Response
    { try { $from = $request->queryString('from', ''); $to = $request->queryString('to', ''); return $this->success($this->subscriptions->summary($this->scope($request), $from === '' ? null : $from, $to === '' ? null : $to), 'Recurring revenue summary'); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); } }
    public function retry(Request $request): Response
    { try { $user = $request->user(); $actor = is_array($user) ? Arr::str($user, 'email', 'admin') : 'admin'; $correlation = is_string($request->getAttribute('correlation_id')) ? $request->getAttribute('correlation_id') : ''; return $this->success($this->subscriptions->retry(Arr::requiredParam($request, 'id'), $this->scope($request), (string) $request->header('Idempotency-Key', ''), $actor, $correlation), 'Renewal retry queued'); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); } catch (\RuntimeException $e) { return $this->error($e->getMessage(), 409, [], 'CONFLICT'); } }
    private function scope(Request $request): CommerceScope
    { $scope = CommerceScope::fromRequest($request); $user = $request->user(); ScopeAuthorizer::assertAllowed($scope, is_array($user) ? $user : []); return $scope; }
}
