<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Wishlist\Application\WishlistService;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;
use App\Resources\WishlistResource;

final class WishlistController extends Controller
{
    public function __construct(private readonly WishlistService $wishlist) {}

    public function index(Request $request): Response { return $this->success(array_map(static fn (array $row): array => WishlistResource::make($row), $this->wishlist->list($this->customerId($request))), 'Wishlist'); }

    public function add(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['product_id' => 'required|string', 'variant_id' => 'nullable|string']);
        return $this->success($this->wishlist->add($this->customerId($request), $data), 'Wishlist item saved');
    }

    public function remove(Request $request): Response
    {
        $this->wishlist->remove($this->customerId($request), Arr::requiredParam($request, 'product'));
        return $this->noContent();
    }

    private function customerId(Request $request): string
    {
        $user = $request->user();
        if (!is_array($user) || !is_numeric($user['id'] ?? null)) throw new \RuntimeException('Unauthorized');
        return (string) (int) $user['id'];
    }
}
