<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Models\MemberPrice;
use App\Modules\Promotion\Application\MemberPriceService;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class MemberPriceController extends Controller
{
    public function __construct(private readonly MemberPriceService $prices) {}

    public function index(Request $request): Response
    {
        $variant = trim((string) $request->query('variant_id', ''));
        $query = MemberPrice::query()->orderBy('variant_id', 'asc');
        if ($variant !== '') $query = $query->where('variant_id', $variant);
        $out = [];
        foreach ($query->get() as $m) $out[] = Arr::row($m);
        return $this->success($out, 'Member prices');
    }

    public function store(Request $request): Response
    {
        $key = $request->header('Idempotency-Key', '');
        if (!is_string($key) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) return $this->error('A valid Idempotency-Key is required', 422);
        $data = $request->validate(['variant_id' => 'required|string', 'tier' => 'required|string', 'price_minor' => 'required|integer', 'currency' => 'nullable|string']);
        try {
            return $this->created($this->prices->setPrice($data), 'Member price saved');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function destroy(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        $row = MemberPrice::query()->where('id', $id)->first();
        if ($row === null) return $this->error('Member price not found', 404);
        $row->delete();
        return $this->success(null, 'Member price removed');
    }
}
