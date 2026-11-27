<?php

declare(strict_types=1);

namespace App\Extensions\Siro\ProductPhotoReviews;

use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/** Thin HTTP layer: validate -> service. Served under /api/ext/siro/product-photo-reviews/. */
final class PhotoReviewsController extends Controller
{
    public function __construct(private readonly PhotoReviewsService $service = new PhotoReviewsService())
    {
    }

    public function publicGallery(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
            $productId = $request->queryString('product_id', '');
            $limit = $request->queryString('limit', '');
            $rows = $this->service->listGallery($scope, $productId, $limit !== '' && is_numeric($limit) ? (int) $limit : PhotoReviewsService::MAX_GALLERY);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
        return $this->success($rows, 'Review photos');
    }

    public function adminList(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
            $state = $request->queryString('state', 'pending');
            $productId = $request->queryString('product_id', '');
            $rows = $this->service->adminQueue($scope, $state, $productId !== '' ? $productId : null);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
        return $this->success($rows, 'Photo moderation queue');
    }

    public function adminOverview(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
            $data = $this->service->adminOverview($scope);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        return $this->success($data, 'Photo reviews overview');
    }

    public function moderateMedia(Request $request): Response
    {
        $data = $request->validate([
            'status' => 'required|string|in:approved,rejected',
            'moderation_note' => 'nullable|string|max:500',
        ]);
        try {
            $scope = CommerceScope::fromRequest($request);
            $id = $request->param('id', '');
            $row = $this->service->moderateMedia($scope, is_string($id) ? $id : '', $this->stringValue($data['status'] ?? null), isset($data['moderation_note']) && is_string($data['moderation_note']) ? $data['moderation_note'] : null);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 409);
        }
        if ($row === null) {
            return $this->error('Photo not found', 404);
        }
        return $this->success($row, 'Photo moderated');
    }

    public function moderateReview(Request $request): Response
    {
        $data = $request->validate([
            'status' => 'required|string|in:approved,rejected,pending',
            'moderation_note' => 'nullable|string|max:500',
        ]);
        try {
            $scope = CommerceScope::fromRequest($request);
            $id = $request->param('id', '');
            $row = $this->service->moderateReview(is_string($id) ? $id : '', $this->stringValue($data['status'] ?? null), isset($data['moderation_note']) && is_string($data['moderation_note']) ? $data['moderation_note'] : null);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
        if ($row === null) {
            return $this->error('Review not found', 404);
        }
        return $this->success($row, 'Review moderated');
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
