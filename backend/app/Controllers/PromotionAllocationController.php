<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Modules\Platform\Infrastructure\PromotionAllocationService;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;
final class PromotionAllocationController extends Controller
{
    public function __construct(private readonly PromotionAllocationService $service) {}
    public function reconcile(Request $request): Response
    {
        $body = $request->body(); $allocations = $body['allocations'] ?? null;
        if (!is_array($allocations)) return $this->error('allocations must be an array', 422);
        $rows = [];
        foreach ($allocations as $allocation) { if (!is_array($allocation)) return $this->error('allocation must be an object', 422); $rows[] = Arr::row($allocation); }
        try { return $this->success($this->service->reconcile(Arr::requiredParam($request, 'id'), $rows, (string) $request->header('Idempotency-Key', '')), 'Promotion allocations reconciled'); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }
}
