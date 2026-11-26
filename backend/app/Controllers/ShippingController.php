<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Shipping\Application\ShippingService;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;
use App\Support\Arr;
use Siro\Core\Env;
use App\Support\PublicErrorMessage;

final class ShippingController extends Controller
{
    public function __construct(private readonly ShippingService $shipping) {}

    public function quote(Request $request): Response
    {
        try { return $this->success($this->shipping->quote($request->validate(['origin' => 'required|array', 'destination' => 'required|array', 'packages' => 'required|array', 'provider' => 'nullable|string'])), 'Shipping rates'); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error(PublicErrorMessage::from($e->getMessage(), 'Shipping request could not be completed'), 422); }
    }

    public function create(Request $request): Response
    {
        try { return $this->created($this->shipping->enqueueCreate($request->validate(['shipment' => 'required|array']), $this->key($request)), 'Shipment queued'); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error(PublicErrorMessage::from($e->getMessage(), 'Shipping request could not be completed'), 422); }
    }

    public function track(Request $request): Response
    {
        try { return $this->success($this->shipping->track(Arr::requiredParam($request, 'id')), 'Shipment tracked'); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error(PublicErrorMessage::from($e->getMessage(), 'Shipping request could not be completed'), 422); }
    }

    public function cancel(Request $request): Response
    {
        try { return $this->success($this->shipping->cancel(Arr::requiredParam($request, 'id'), $this->key($request)), 'Shipment cancelled'); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error(PublicErrorMessage::from($e->getMessage(), 'Shipping request could not be completed'), 422); }
    }

    public function webhook(Request $request): Response
    {
        $rawBody = \Siro\Core\Request::getRawBodyCache() ?? (string) json_encode($request->body(), JSON_UNESCAPED_SLASHES);
        $provider = is_string($request->param('provider') ?? null) ? (string) $request->param('provider') : 'dhl';
        $provider = strtolower($provider);
        $signature = $request->header('x-' . $provider . '-signature', '');
        $secret = trim((string) Env::get(strtoupper($provider) . '_WEBHOOK_SECRET', ''));
         $given = is_string($signature) && str_starts_with(strtolower($signature), 'sha256=') ? substr($signature, 7) : $signature;
         if ($secret === '' || !is_string($given) || !hash_equals(hash_hmac('sha256', $rawBody, $secret), $given)) return $this->error('Invalid shipping webhook signature', 401);
        try {
            $data = $request->validate(['event_id' => 'required|string', 'status' => 'required|string', 'tracking_number' => 'nullable|string', 'provider_reference' => 'nullable|string']);
            $data['provider'] = $provider;
            return $this->success($this->shipping->webhook($data), 'Shipping webhook accepted');
        }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error(PublicErrorMessage::from($e->getMessage(), 'Shipping webhook could not be processed'), 422); }
    }

    private function key(Request $request): string
    {
        $key = $request->header('idempotency-key', '');
        return is_string($key) ? $key : '';
    }
}
