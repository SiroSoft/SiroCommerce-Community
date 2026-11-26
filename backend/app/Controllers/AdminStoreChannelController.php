<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Modules\StoreChannel\Application\StoreChannelService; use App\Support\Arr;
use Siro\Core\Controller; use Siro\Core\Request; use Siro\Core\Response;
final class AdminStoreChannelController extends Controller {
 public function __construct(private readonly StoreChannelService $service) {}
 public function stores(): Response { return $this->success($this->service->stores(), 'Stores'); }
 public function channels(Request $request): Response { return $this->success($this->service->channels(Arr::requiredParam($request,'store')), 'Channels'); }
 public function createStore(Request $request): Response { $data=$request->validate(['code'=>'required|string','name'=>'required|string','status'=>'nullable|string']); return $this->created($this->service->createStore($data),'Store created'); }
 public function createChannel(Request $request): Response { $data=$request->validate(['store_id'=>'required|string','code'=>'required|string','name'=>'required|string','status'=>'nullable|string']); return $this->created($this->service->createChannel($data),'Channel created'); }
}
