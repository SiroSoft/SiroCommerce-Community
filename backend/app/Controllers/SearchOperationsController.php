<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Search\Contracts\SearchAdapter;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class SearchOperationsController extends Controller
{
    public function __construct(private readonly SearchAdapter $search) {}

    public function status(): Response { return $this->success($this->search->status(), 'Search index status'); }

    public function reindex(Request $request): Response
    {
        try { return $this->success($this->search->enqueueReindex(['type' => $request->body()['type'] ?? 'all']), 'Search reindex queued', 202); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
    }
}
