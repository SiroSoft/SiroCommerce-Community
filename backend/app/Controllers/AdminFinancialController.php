<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Modules\Finance\Application\AdminFinancialReconciliationService;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use App\Support\ScopeAuthorizer;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;
final class AdminFinancialController extends Controller
{
    public function __construct(private readonly AdminFinancialReconciliationService $finance) {}
    public function reconciliation(Request $request): Response
    {
        try { $scope = CommerceScope::fromRequest($request); ScopeAuthorizer::assertAllowed($scope, is_array($request->user()) ? $request->user() : []); $result = $this->finance->list($scope, $request->queryInt('page', 1), $request->queryInt('per_page', 25)); return $this->paginated($result['data'], $result['meta'], 'Reconciliation'); }
        catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); }
    }
}
