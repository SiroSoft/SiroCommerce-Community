<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Analytics\Application\AnalyticsService;
use App\Modules\Analytics\Domain\AnalyticsEventSchema;
use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\ReportingScope;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analytics)
    {
    }

    public function track(Request $request): Response
    {
        $body = $request->body();
        $eventValue = $body['event'] ?? null;
        if (is_string($eventValue) && AnalyticsEventSchema::acceptsStorefront($eventValue)) {
            try {
                if (($body['analytics_consent'] ?? true) === false) {
                    return $this->success(['tracked' => false], 'Analytics opt-out honored');
                }
                $scope = $this->storefrontScope($body);
                $eventId = $body['event_id'] ?? null;
                $session = $body['session_id'] ?? null;
                if (!is_string($eventId) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $eventId) !== 1) throw new \InvalidArgumentException('A valid event_id is required');
                if (!is_string($session) || preg_match('/^[A-Za-z0-9_-]{16,128}$/', $session) !== 1) throw new \InvalidArgumentException('A valid session_id is required');
                if (strlen(json_encode($body, JSON_THROW_ON_ERROR)) > 8192) throw new \InvalidArgumentException('Analytics payload is too large');
                $row = $this->analytics->trackStorefront($eventValue, $body['properties'] ?? null, $eventId, $session, $scope['store_id'], $scope['channel_id'], $scope['locale'], $scope['currency']);
                return $this->created($row, 'Tracked');
            } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422, [], 'INVALID_ANALYTICS_EVENT'); }
            catch (\JsonException) { return $this->error('Malformed analytics payload', 422, [], 'INVALID_ANALYTICS_EVENT'); }
        }
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'event' => 'required|string',
            'entity_type' => 'nullable|string',
            'entity_id' => 'nullable|string',
            'session_key' => 'nullable|string',
            'value_minor' => 'nullable|integer',
            'store_id' => 'nullable|string',
            'channel_id' => 'nullable|string',
        ]);
        $event = Arr::requiredStr($data, 'event');
        if (!$this->analytics->isAllowed($event)) {
            return $this->error('Unknown event', 422, ['event' => ['Unknown event']]);
        }
        try { [$storeId, $channelId] = ReportingScope::validate(Arr::nullableStr($data, 'store_id'), Arr::nullableStr($data, 'channel_id')); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422, [], 'INVALID_REPORTING_SCOPE'); }
        $row = $this->analytics->track(
            $event,
            Arr::nullableStr($data, 'entity_type'),
            Arr::nullableStr($data, 'entity_id'),
            Arr::nullableStr($data, 'session_key'),
            Arr::int($data, 'value_minor'),
            $storeId,
            $channelId
        );
        return $this->created($row, 'Tracked');
    }

    /** @param array<mixed, mixed> $body @return array{store_id:string,channel_id:string,locale:string,currency:string} */
    private function storefrontScope(array $body): array
    {
        $allowed = ['event', 'event_id', 'session_id', 'properties', 'store_id', 'channel_id', 'locale', 'currency', 'analytics_consent'];
        foreach (array_keys($body) as $key) if (!is_string($key) || !in_array($key, $allowed, true)) throw new \InvalidArgumentException('Unsupported analytics field');
        foreach (['store_id', 'channel_id', 'locale', 'currency'] as $key) if (!isset($body[$key]) || !is_string($body[$key]) || trim($body[$key]) === '') throw new \InvalidArgumentException('Explicit analytics scope is required');
        $scope = new CommerceScope(trim((string) $body['store_id']), trim((string) $body['channel_id']), strtolower(trim((string) $body['locale'])), strtoupper(trim((string) $body['currency'])));
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $scope->storeId) !== 1 || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $scope->channelId) !== 1 || !in_array($scope->locale, CommerceScope::ALLOWED_LOCALES, true) || !in_array($scope->currency, CommerceScope::ALLOWED_CURRENCIES, true)) throw new \InvalidArgumentException('Invalid analytics scope');
        ReportingScope::validate($scope->storeId, $scope->channelId);
        return ['store_id' => $scope->storeId, 'channel_id' => $scope->channelId, 'locale' => $scope->locale, 'currency' => $scope->currency];
    }

    public function funnel(Request $request): Response
    {
        return $this->success($this->analytics->funnel()->toArray(), 'Funnel')->header('Cache-Control', 'private, no-store');
    }

    public function events(Request $request): Response
    {
        try {
            [$from, $to] = $this->window($request->queryString('from'), $request->queryString('to'));
            [$storeId, $channelId] = ReportingScope::validate($request->queryString('store_id'), $request->queryString('channel_id'));
            $result = $this->analytics->read($from, $to, $request->queryString('event'), max(1, $request->queryInt('page', 1)), min(100, max(1, $request->queryInt('per_page', 50))), $storeId, $channelId);
            /** @var array{page: int, per_page: int, total: int, last_page: int} $meta */
            $meta = $result['meta'];
            return $this->paginated($result['data'], $meta, 'Analytics events')->header('Cache-Control', 'private, no-store');
        } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422, [], 'INVALID_REPORTING_SCOPE'); }
    }

    public function export(Request $request): Response
    {
        try {
            [$from, $to] = $this->window($request->queryString('from'), $request->queryString('to'));
            return $this->success($this->analytics->export($from, $to, $request->queryString('event'), max(1, $request->queryInt('page', 1)), min(100, max(1, $request->queryInt('per_page', 100)))), 'Analytics export')->header('Cache-Control', 'private, no-store');
        } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
    }

    /** @return array{0: string, 1: string} */
    private function window(?string $from, ?string $to): array
    {
        $end = $to === null ? time() : strtotime($to); $start = $from === null ? strtotime('-30 days') : strtotime($from);
        $startDate = $start === false ? '' : date('Y-m-d', $start);
        $endDate = $end === false ? '' : date('Y-m-d', $end);
        $calendarDays = ($startDate !== '' && $endDate !== '') ? (int) ((strtotime($endDate) - strtotime($startDate)) / 86400) + 1 : 0;
        if ($end === false || $start === false || $start > $end || $calendarDays > 31) throw new \InvalidArgumentException('Analytics window must be 1-31 days');
        return [date('Y-m-d 00:00:00', $start), date('Y-m-d 23:59:59', $end)];
    }
}
