<?php

declare(strict_types=1);

namespace App\Extensions\Siro\EmailCapturePopup;

use App\Modules\Consent\Application\ConsentService;
use App\Modules\Coupon\Application\CouponService;
use App\Modules\Customer\Contracts\CustomerReader;
use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Audit;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Thin HTTP layer for siro/email-capture-popup.
 * Served under /api/ext/siro/email-capture-popup/ (spec RULE-E4).
 *
 * Public subscribe flow (storefront):
 *   1. POST /api/consent/marketing (core) records the opt-in first.
 *   2. POST subscribe here stores the email in the extension table and,
 *      only when ConsentService::hasMarketingConsent() is true, issues the
 *      welcome coupon and sends the welcome mail through the registry.
 * Calling subscribe without a prior opt-in stores the row with
 * consented_at=null and sends nothing.
 */
final class EmailCaptureController extends Controller
{
    private EmailCaptureService $service;
    private CouponService $coupons;

    public function __construct(
        ?EmailCaptureService $service = null,
        ?CouponService $coupons = null,
        ?ConsentService $consent = null,
        ?CustomerReader $customers = null,
    ) {
        // Constructor DI in production (Router resolves via the container);
        // the nullable fallbacks keep `new EmailCaptureController()` working
        // in tests that do not boot the container.
        $this->coupons = $coupons ?? $this->containerCouponService();
        $this->service = $service ?? new EmailCaptureService(
            $consent ?? $this->containerConsentService(),
            $this->coupons,
            $customers ?? $this->containerCustomerReader(),
        );
    }

    /** Public popup config for the storefront (throttled, no auth). */
    public function publicConfig(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        return $this->success($this->service->publicConfig($scope), 'Email capture popup');
    }

    /** Public subscribe (throttled + Idempotency-Key via the loader). */
    public function subscribe(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'email' => 'required|string|max:254',
            'source' => 'nullable|string|max:32',
            'session_id' => 'nullable|string|max:128',
        ]);
        $email = Arr::str($data, 'email');
        $source = Arr::str($data, 'source', 'popup');
        $sessionId = Arr::str($data, 'session_id');
        $headerSession = $request->header('X-Session-Id');
        if ($sessionId === '' && is_string($headerSession) && trim($headerSession) !== '') {
            $sessionId = trim($headerSession);
        }
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        try {
            $result = $this->service->subscribe($scope, $email, $source, $sessionId === '' ? null : $sessionId);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 500);
        }
        $created = ($result['already_subscribed'] ?? false) === false;
        return $created
            ? $this->created($result, 'Subscribed')
            : $this->success($result, 'Already subscribed');
    }

    /** Admin: subscriber stats panel. */
    public function stats(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        return $this->success($this->service->stats($scope), 'Email capture stats');
    }

    /** Admin: subscriber table panel (scoped, paginated). */
    public function subscribers(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        $page = $request->queryInt('page', 1);
        $perPage = $request->queryInt('per_page', 20);
        $listed = $this->service->list($scope, $page, $perPage);
        return $this->success($listed + ['table' => $this->table($listed['rows'])], 'Email capture subscribers');
    }

    /** Admin: delete one subscriber (scoped; audit-logged). */
    public function deleteSubscriber(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        $deleted = $this->service->delete($scope, $id);
        if (!$deleted) {
            return $this->error('Subscriber not found', 404);
        }
        Audit::log('ext.email-capture-popup.subscriber.deleted', [
            'actor' => $this->actor($request),
            'subscriber_id' => $id,
            'store_id' => $scope->storeId,
            'channel_id' => $scope->channelId,
        ]);
        return $this->success(['deleted' => true, 'id' => $id], 'Subscriber deleted');
    }

    /** Admin: create a coupon (cap:coupon:create, SDK §2.6 contract). */
    public function createCoupon(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'code' => 'required|string',
            'rules' => 'nullable|string',
        ]);
        $code = Arr::requiredStr($data, 'code');
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{2,63}\z/', $code) !== 1) {
            return $this->error('Coupon code must be 3-64 letters, numbers, hyphens, or underscores', 422, ['code' => ['Invalid coupon code']]);
        }
        $rules = Arr::str($data, 'rules', '{}');
        $decoded = json_decode($rules, true);
        if (!is_array($decoded) || (array_is_list($decoded) && trim($rules) !== '{}')) {
            return $this->error('Coupon rules must be a JSON object', 422, ['rules' => ['Invalid JSON object']]);
        }
        try {
            $coupon = $this->coupons->create([
                'code' => strtoupper($code),
                'rules' => json_encode($decoded, JSON_THROW_ON_ERROR),
                'state' => 'active',
            ]);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
        Audit::log('ext.email-capture-popup.coupon.created', [
            'actor' => $this->actor($request),
            'code' => strtoupper($code),
        ]);
        return $this->created($coupon, 'Coupon created');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{columns: list<array{key: string, label: string}>, rows: list<array<string, string>>}
     */
    private function table(array $rows): array
    {
        $columns = [
            ['key' => 'email', 'label' => 'Email'],
            ['key' => 'source', 'label' => 'Source'],
            ['key' => 'coupon_code', 'label' => 'Coupon'],
            ['key' => 'consented_at', 'label' => 'Consented at'],
            ['key' => 'created_at', 'label' => 'Subscribed at'],
        ];
        $tableRows = [];
        foreach ($rows as $row) {
            $tableRow = [];
            foreach ($columns as $column) {
                $tableRow[$column['key']] = $this->stringValue($row[$column['key']] ?? null);
            }
            $tableRows[] = $tableRow;
        }
        return ['columns' => $columns, 'rows' => $tableRows];
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return is_scalar($value) ? (string) $value : '';
    }

    private function actor(Request $request): string
    {
        $user = $request->user();
        if (is_array($user)) {
            $email = is_string($user['email'] ?? null) ? $user['email'] : '';
            if ($email !== '') {
                return $email;
            }
            $id = $user['id'] ?? null;
            if (is_scalar($id)) {
                return (string) $id;
            }
        }
        return 'admin';
    }

    private function containerCouponService(): CouponService
    {
        $made = \Siro\Core\Container::getInstance()->make(CouponService::class);
        if (!$made instanceof CouponService) {
            throw new \RuntimeException('CouponService could not be resolved');
        }
        return $made;
    }

    private function containerConsentService(): ConsentService
    {
        $made = \Siro\Core\Container::getInstance()->make(ConsentService::class);
        if (!$made instanceof ConsentService) {
            throw new \RuntimeException('ConsentService could not be resolved');
        }
        return $made;
    }

    private function containerCustomerReader(): CustomerReader
    {
        $made = \Siro\Core\Container::getInstance()->make(CustomerReader::class);
        if (!$made instanceof CustomerReader) {
            throw new \RuntimeException('CustomerReader could not be resolved');
        }
        return $made;
    }
}
