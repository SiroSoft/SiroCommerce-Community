<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Arr;
use App\Support\LocaleResolver;
use App\Extension\ExtensionRegistry;
use App\Modules\Webhook\Application\WebhookReceiver;
use App\Modules\Payment\Infrastructure\PaymentProviderRegistry;
use Siro\Core\Controller;
use Siro\Core\Lang;
use Siro\Core\Request;
use Siro\Core\Response;
use App\Support\PublicErrorMessage;

/**
 * Provider webhooks verify raw-body HMAC before parsing. COD has no webhook
 * contract and must not be reported as already processed.
 */
final class WebhookController extends Controller
{
    public function __construct(
        private readonly WebhookReceiver $receiver,
        private readonly PaymentProviderRegistry $providers,
    )
    {
    }

    public function handle(Request $request): Response
    {
        Lang::setLocale(LocaleResolver::resolve($request));
        $provider = Arr::requiredParam($request, 'provider');
        $gateway = ExtensionRegistry::payment($provider);
        $eventProvider = $provider;
        if ($gateway === null) {
            try {
                $eventProvider = PaymentProviderRegistry::canonical($provider);
                if ($eventProvider === 'cod') {
                    return $this->error('Webhook provider is not configured', 404);
                }
                $gateway = $this->providers->provider($eventProvider);
            } catch (\InvalidArgumentException|\RuntimeException) {
                return $this->error('Webhook provider is not configured', 404);
            }
        }
        $rawBody = \Siro\Core\Request::getRawBodyCache() ?? (string) json_encode($request->body(), JSON_UNESCAPED_SLASHES);
        if (!$gateway->verifyWebhook($rawBody, $request->headers())) {
            return $this->error(Lang::get('commerce.payment.invalid_signature'), 401);
        }
        $headerEventId = $request->header('x-event-id', '');
        $eventId = is_string($headerEventId) ? $headerEventId : '';
        if ($eventId === '') {
            $body = $request->body();
            $eventId = is_string($body['event_id'] ?? null) ? $body['event_id'] : '';
        }
        if ($eventId === '') {
            return $this->error('Webhook event_id is required', 422, ['event_id' => ['Required']]);
        }
        try {
            $result = $this->receiver->receive($eventProvider, $eventId, $rawBody, true, $request->headers());
        } catch (\InvalidArgumentException $e) {
            return $this->error(PublicErrorMessage::from($e->getMessage(), 'Webhook signature could not be verified'), 401);
        }
        return $this->success($result, 'Webhook accepted');
    }
}
