<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Smart\Application\SmartService;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Smart surfaces (spec 06). Intelligence inside the workflow —
 * no separate AI module, works without any LLM.
 */
final class SmartController extends Controller
{
    public function __construct(
        private readonly SmartService $runtime,
    ) {
    }

    public function actionCenter(Request $request): Response
    {
        return $this->success($this->runtime->actionCenter(), 'Action center');
    }

    public function rescue(Request $request): Response
    {
        $user = $request->user();
        /** @var array<string, mixed>|null $user */
        $actor = is_array($user) ? Arr::str($user, 'email', 'admin') : 'admin';
        try {
            $report = $this->runtime->rescue(Arr::requiredParam($request, 'id'), $actor);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        }
        return $this->success($report, 'Order rescue report');
    }

    public function search(Request $request): Response
    {
        $result = $this->runtime->search($request->queryString('q', ''));
        return $this->success($result, 'Search results');
    }

    public function execute(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['command' => 'required|string']);
        $user = $request->user();
        /** @var array<string, mixed>|null $user */
        $actor = is_array($user) ? Arr::str($user, 'email', 'admin') : 'admin';
        $result = $this->runtime->execute(Arr::requiredStr($data, 'command'), $actor);
        if (!$result['ok']) {
            $message = $result['message'];
            return $this->error($message, 422);
        }
        $message = $result['message'];
        return $this->success($result, $message);
    }

    public function inventory(Request $request): Response
    {
        return $this->success($this->runtime->inventory(), 'Inventory insights');
    }

    public function setupGuide(Request $request): Response
    {
        return $this->success($this->runtime->setup(), 'Setup guide');
    }

    public function conversion(Request $request): Response
    {
        return $this->success($this->runtime->conversion(), 'Conversion watch');
    }
}
