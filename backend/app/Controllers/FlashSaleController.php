<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Models\FlashSale;
use App\Models\FlashSaleItem;
use App\Modules\Promotion\Application\FlashSaleService;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class FlashSaleController extends Controller
{
    public function __construct(private readonly FlashSaleService $sales) {}

    public function index(Request $request): Response
    {
        $rows = FlashSale::query()->orderBy('starts_at', 'desc')->get();
        $out = [];
        foreach ($rows as $r) {
            $row = Arr::row($r);
            $items = [];
            foreach (FlashSaleItem::query()->where('sale_id', Arr::str($row, 'id'))->get() as $i) $items[] = Arr::row($i);
            $row['items'] = $items;
            $out[] = $row;
        }
        return $this->success($out, 'Flash sales');
    }

    public function store(Request $request): Response
    {
        $key = $request->header('Idempotency-Key', '');
        if (!is_string($key) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) return $this->error('A valid Idempotency-Key is required', 422);
        $data = $request->validate(['name' => 'required|string', 'starts_at' => 'required|string', 'ends_at' => 'required|string', 'state' => 'nullable|string', 'priority' => 'nullable|integer']);
        try {
            return $this->created($this->sales->createSale($data), 'Flash sale created');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function update(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        $row = FlashSale::query()->where('id', $id)->first();
        if ($row === null) return $this->error('Flash sale not found', 404);
        $data = $request->validate(['name' => 'nullable|string', 'starts_at' => 'nullable|string', 'ends_at' => 'nullable|string', 'state' => 'nullable|string', 'priority' => 'nullable|integer', 'min_tier' => 'nullable|string']);
        $patch = [];
        if (array_key_exists('min_tier', $data)) {
            $raw = trim((string) ($data['min_tier'] ?? ''));
            if ($raw !== '' && \App\Modules\Promotion\Application\MemberPriceService::normalizeTier($raw) === null) return $this->error('min_tier must be bronze|silver|gold|platinum', 422);
            $patch['min_tier'] = $raw !== '' ? strtolower($raw) : null;
        }
        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '' || mb_strlen($name) > 120) return $this->error('Invalid sale name', 422);
            $patch['name'] = $name;
        }
        if (isset($data['state'])) {
            $state = strtolower(trim((string) $data['state']));
            if (!in_array($state, ['draft', 'active', 'archived'], true)) return $this->error('Invalid sale state', 422);
            $patch['state'] = $state;
        }
        if (isset($data['starts_at']) || isset($data['ends_at'])) {
            $starts = isset($data['starts_at']) ? (string) $data['starts_at'] : Arr::str($row->toArray(), 'starts_at');
            $ends = isset($data['ends_at']) ? (string) $data['ends_at'] : Arr::str($row->toArray(), 'ends_at');
            if (strtotime($starts) === false || strtotime($ends) === false || strtotime($starts) >= strtotime($ends)) return $this->error('Invalid sale window', 422);
            $patch['starts_at'] = date('Y-m-d H:i:s', strtotime($starts));
            $patch['ends_at'] = date('Y-m-d H:i:s', strtotime($ends));
        }
        if (isset($data['priority'])) $patch['priority'] = max(0, (int) $data['priority']);
        if ($patch === []) return $this->error('No changes', 422);
        $patch['updated_at'] = date('Y-m-d H:i:s');
        $row->fill($patch);
        $row->save();
        return $this->success(Arr::row($row), 'Flash sale updated');
    }

    public function addItem(Request $request): Response
    {
        $saleId = Arr::requiredParam($request, 'id');
        if (FlashSale::query()->where('id', $saleId)->first() === null) return $this->error('Flash sale not found', 404);
        $key = $request->header('Idempotency-Key', '');
        if (!is_string($key) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) return $this->error('A valid Idempotency-Key is required', 422);
        $data = $request->validate(['variant_id' => 'required|string', 'sale_price_minor' => 'required|integer', 'cap_qty' => 'nullable|integer']);
        try {
            return $this->created($this->sales->addItem($saleId, $data), 'Flash sale item added');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 409);
        }
    }

    public function removeItem(Request $request): Response
    {
        $saleId = Arr::requiredParam($request, 'id');
        $itemId = Arr::requiredParam($request, 'item');
        $row = FlashSaleItem::query()->where('id', $itemId)->where('sale_id', $saleId)->first();
        if ($row === null) return $this->error('Flash sale item not found', 404);
        $row->delete();
        return $this->success(null, 'Flash sale item removed');
    }
}
