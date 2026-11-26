<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Modules\Platform\Infrastructure\BiExportService;
use App\Resources\BiExportResource;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;
final class BiExportController extends Controller
{
    public function __construct(private readonly BiExportService $exports) {}
    public function create(Request $request): Response
    {
        try { $data = $request->validate(['dataset' => 'required|string', 'format' => 'required|string', 'from' => 'nullable|string', 'to' => 'nullable|string', 'store_id' => 'nullable|string', 'channel_id' => 'nullable|string']); return $this->created(BiExportResource::make($this->exports->create(Arr::requiredStr($data, 'dataset'), Arr::requiredStr($data, 'format'), Arr::nullableStr($data, 'from'), Arr::nullableStr($data, 'to'), Arr::nullableStr($data, 'store_id'), Arr::nullableStr($data, 'channel_id'))), 'Export queued'); }
         catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422, [], 'INVALID_REPORTING_SCOPE'); }
    }
    public function status(Request $request): Response { try { return $this->success(BiExportResource::make($this->exports->status(Arr::requiredParam($request, 'id'))), 'Export status'); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 404); } }
    public function download(Request $request): Response { try { return $this->success(BiExportResource::make($this->exports->downloadMetadata(Arr::requiredParam($request, 'id'))), 'Export download metadata')->header('Cache-Control', 'private, no-store'); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 409); } }
    public function retry(Request $request): Response { try { return $this->success(BiExportResource::make($this->exports->retry(Arr::requiredParam($request, 'id'))), 'Export retry queued'); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); } }
}
