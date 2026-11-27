<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Review\Application\ReviewService;
use App\Support\Arr;
use App\Resources\ReviewResource;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    public function index(Request $request): Response { return $this->success(array_map(static fn (array $row): array => ReviewResource::make($row), $this->reviews->published(Arr::requiredParam($request, 'id'))), 'Reviews'); }

    public function store(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['order_id' => 'required|string', 'order_line_id' => 'required|string', 'rating' => 'required|integer', 'title' => 'nullable|string', 'body' => 'required|string', 'images' => 'nullable|array']);
        if (!is_int($data['rating'] ?? null) || $data['rating'] < 1 || $data['rating'] > 5) return $this->error('Rating must be between 1 and 5', 422);
        try { $review = $this->reviews->submit($this->customerId($request), Arr::requiredParam($request, 'id'), $data); }
        catch (\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
        return $this->created($review, 'Review submitted for moderation');
    }

    public function adminIndex(): Response { return $this->success(array_map(static fn (array $row): array => ReviewResource::make($row), $this->reviews->all()), 'Reviews'); }

    public function moderate(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['status' => 'required|string', 'moderation_note' => 'nullable|string']);
        try { $review = $this->reviews->moderate(Arr::requiredParam($request, 'id'), Arr::requiredStr($data, 'status'), Arr::str($data, 'moderation_note')); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
        return $review === null ? $this->error('Review not found', 404) : $this->success($review, 'Review moderated');
    }

    private function customerId(Request $request): string
    {
        $user = $request->user();
        if (!is_array($user) || !is_numeric($user['id'] ?? null)) throw new \RuntimeException('Unauthorized');
        return (string) (int) $user['id'];
    }
}
