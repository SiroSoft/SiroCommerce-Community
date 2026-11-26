<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Payment\Application\PaymentCommandService;
use App\Modules\Payment\Application\PaymentReconciliationService;
use App\Modules\Payment\Contracts\PaymentReconciliationProvider;
use App\Modules\Payment\Contracts\PaymentStore;
use App\Support\Arr;
use App\Support\PublicErrorMessage;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class PaymentController extends Controller
{
    public function __construct(private readonly PaymentCommandService $payments, private readonly PaymentStore $paymentStore, private readonly PaymentReconciliationService $reconciliation, private readonly PaymentReconciliationProvider $reconciliationProvider) {}

    public function authorize(Request $request): Response { return $this->run($request, 'authorize'); }
    public function capture(Request $request): Response { return $this->run($request, 'capture'); }
    public function refund(Request $request): Response { return $this->run($request, 'refund'); }
    public function void(Request $request): Response { return $this->run($request, 'void'); }

    public function status(Request $request): Response
    {
        $payment = $this->paymentStore->find(Arr::requiredParam($request, 'id'));
        if ($payment === null) return $this->error('Payment not found', 404);
        unset($payment['idempotency_key']);
        return $this->success($payment, 'Payment status');
    }

    public function proof(Request $request): Response
    {
        $paymentId = Arr::requiredParam($request, 'id');
        $payment = $this->paymentStore->find($paymentId);
        if ($payment === null) return $this->error('Payment not found', 404);
        $key = $request->header('Idempotency-Key', '');
        if (!is_string($key) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) return $this->error('A valid Idempotency-Key is required', 422);
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'reference' => 'required|string',
            'file_url' => 'nullable|string',
            'file_hash' => 'nullable|string',
        ]);
        $reference = trim(Arr::requiredStr($data, 'reference'));
        if (!preg_match('/^[A-Za-z0-9 _-]{3,128}$/', $reference)) return $this->error('Proof reference must be 3-128 letters, numbers, spaces, hyphens or underscores', 422, ['reference' => ['Invalid reference']]);
        $fileUrl = Arr::nullableStr($data, 'file_url');
        if ($fileUrl !== null && $fileUrl !== '') {
            if (strlen($fileUrl) > 512) return $this->error('Proof file URL is too long', 422, ['file_url' => ['Too long']]);
            $isUpload = str_starts_with($fileUrl, '/uploads/');
            $isHttp = filter_var($fileUrl, FILTER_VALIDATE_URL) !== false && preg_match('~^https?://~i', $fileUrl) === 1;
            if (!$isUpload && !$isHttp) return $this->error('Proof file URL must be http(s) or /uploads/...', 422, ['file_url' => ['Invalid URL']]);
            if (preg_match('/(?:javascript|data|<script)/i', $fileUrl) === 1) return $this->error('Proof file URL contains forbidden content', 422, ['file_url' => ['Invalid URL']]);
            $proofPath = $isHttp ? (string) parse_url($fileUrl, PHP_URL_PATH) : $fileUrl;
            if ($proofPath === '' || preg_match('/\.(jpe?g|png|webp|gif|pdf)$/i', $proofPath) !== 1) return $this->error('Proof file URL must be an image or PDF', 422, ['file_url' => ['Invalid URL']]);
        }
        $fileHash = Arr::nullableStr($data, 'file_hash');
        if ($fileHash !== null && $fileHash !== '' && !preg_match('/^[a-f0-9]{64}$/i', $fileHash)) return $this->error('File hash must be 64 hex', 422, ['file_hash' => ['Invalid hash']]);
        // Ownership check: allow auth user or guest token
        $orderId = Arr::str($payment, 'order_id');
        $order = null;
        if ($orderId !== '') {
            try { $order = \Siro\Core\Database::table('orders')->where('id', $orderId)->first(); } catch (\Throwable $e) { $order = null; }
        }
        $user = $request->user();
        $orderToken = (string) $request->header('X-Order-Token', '');
        $orderRow = $order !== null ? Arr::row($order) : [];
        if ($orderRow !== []) {
            $customerId = Arr::str($orderRow, 'customer_id');
            $hasAuth = $user !== null;
            // Guest tokens are stored hashed in guest_order_access — verify via hash,
            // with fallback to legacy plaintext column if present.
            $hasGuest = false;
            if ($orderToken !== '') {
                try {
                    $access = \App\Models\GuestOrderAccess::query()->where('order_id', $orderId)->first();
                    if ($access !== null) {
                        $accessRow = Arr::row($access);
                        $hash = Arr::str($accessRow, 'token_hash');
                        $revoked = $accessRow['revoked_at'] ?? null;
                        $hasGuest = $hash !== '' && $revoked === null && hash_equals($hash, hash('sha256', $orderToken));
                    } else {
                        $legacy = Arr::str($orderRow, 'guest_access_token');
                        $hasGuest = $legacy !== '' && hash_equals($legacy, $orderToken);
                    }
                } catch (\Throwable $e) {
                    $legacy = Arr::str($orderRow, 'guest_access_token');
                    $hasGuest = $legacy !== '' && $orderToken !== '' && hash_equals($legacy, $orderToken);
                }
            }
            if ($customerId !== '') {
                // Registered-user order: only the owning authenticated user (or staff) may submit.
                // Note: user ids are ints — Arr::str would discard them, so cast explicitly.
                $userId = (is_array($user) && isset($user['id']) && is_scalar($user['id'])) ? (string) $user['id'] : '';
                $isStaff = is_array($user) && in_array($user['role'] ?? null, ['admin', 'owner', 'finance', 'support'], true);
                if (!$hasAuth) return $this->error('Payment proof not authorized', 403);
                if (!$isStaff && $userId !== $customerId) return $this->error('Payment proof not authorized', 403);
            } elseif (!$hasAuth && !$hasGuest) {
                // P0: guest order requires the order token (or auth). No open submit.
                return $this->error('Payment proof not authorized', 403);
            }
        } elseif ($user === null) {
            return $this->error('Payment proof not authorized', 403);
        }
        $uploadedBy = null;
        if (is_array($user)) $uploadedBy = Arr::str($user, 'id', Arr::str($user, 'email'));
        $channel = $user !== null ? 'admin' : 'storefront';
        // Idempotent: if proof with same idempotency exists, return it
        try {
            $existing = \App\Models\PaymentProof::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                $payment = $this->paymentStore->find($paymentId);
                if ($payment !== null) unset($payment['idempotency_key']);
                return $this->success(['payment' => $payment, 'proof' => Arr::row($existing)], 'Proof already submitted');
            }
        } catch (\Throwable $e) {}
        // Create proof row and update payment
        try {
            \Siro\Core\Database::transaction(function () use ($paymentId, $orderId, $reference, $fileUrl, $fileHash, $uploadedBy, $channel, $key): void {
                $now = date('Y-m-d H:i:s');
                \App\Models\PaymentProof::create([
                    'id' => \App\Support\Ids::new(),
                    'payment_id' => $paymentId,
                    'order_id' => $orderId,
                    'reference' => $reference,
                    'file_url' => $fileUrl,
                    'file_hash' => $fileHash,
                    'uploaded_by' => $uploadedBy,
                    'channel' => $channel,
                    'idempotency_key' => $key,
                    'created_at' => $now,
                ]);
                $row = \App\Models\Payment::query()->where('id', $paymentId)->first();
                if ($row !== null) {
                    $row->setAttribute('proof_reference', $reference);
                    if ($fileUrl !== null && $fileUrl !== '') $row->setAttribute('proof_url', $fileUrl);
                    $row->setAttribute('proof_uploaded_at', $now);
                    $row->save();
                }
            });
        } catch (\Throwable $e) {
            return $this->error('Proof could not be saved: ' . $e->getMessage(), 422);
        }
        $fresh = $this->paymentStore->find($paymentId);
        if ($fresh !== null) unset($fresh['idempotency_key']);
        $proof = \App\Models\PaymentProof::query()->where('idempotency_key', $key)->first();
        return $this->success(['payment' => $fresh, 'proof' => $proof ? Arr::row($proof) : null], 'Proof submitted');
    }

    public function reconcile(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['apply' => 'nullable|boolean']);
        $queryApply = $request->queryString('apply', '');
        $apply = array_key_exists('apply', $data) ? (bool) $data['apply'] : $queryApply === 'true';
        try { return $this->success($this->reconciliation->reconcile($this->reconciliationProvider, $apply), 'Payment reconciliation'); }
        catch (\RuntimeException $e) { return $this->error(PublicErrorMessage::from($e->getMessage(), 'Payment reconciliation is unavailable'), 503); }
    }

    private function run(Request $request, string $type): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['amount_minor' => 'nullable|integer', 'currency' => 'nullable|string', 'proof_reference' => 'nullable|string', 'proof_url' => 'nullable|string']);
        $key = $request->header('idempotency-key', '');
        if (!is_string($key) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) return $this->error('A valid Idempotency-Key is required', 422);
        $proofRef = Arr::nullableStr($data, 'proof_reference');
        $proofUrl = Arr::nullableStr($data, 'proof_url');
        if ($type === 'capture' && $proofRef !== null && $proofRef !== '' && !preg_match('/^[A-Za-z0-9 _-]{3,128}$/', trim($proofRef))) return $this->error('Proof reference must be 3-128 letters, numbers, spaces, hyphens or underscores', 422);
        if ($proofUrl !== null && $proofUrl !== '') {
            if (strlen($proofUrl) > 512 || preg_match('/(?:javascript|data|<script)/i', $proofUrl) === 1) return $this->error('Proof file URL is invalid', 422);
            $isUpload = str_starts_with($proofUrl, '/uploads/');
            $isHttp = filter_var($proofUrl, FILTER_VALIDATE_URL) !== false && preg_match('~^https?://~i', $proofUrl) === 1;
            if (!$isUpload && !$isHttp) return $this->error('Proof file URL must be http(s) or /uploads/...', 422);
            // P0: only static image/pdf evidence — reject svg/html/php vectors
            // that can carry script when rendered or previewed.
            $capturePath = $isHttp ? (string) parse_url($proofUrl, PHP_URL_PATH) : $proofUrl;
            if ($capturePath === '' || preg_match('/\.(jpe?g|png|webp|gif|pdf)$/i', $capturePath) !== 1) return $this->error('Proof file URL must be an image or PDF', 422);
        }
         try {
             $user = $request->user();
             $context = [
                 'actor' => is_array($user) ? Arr::str($user, 'email', 'system') : 'system',
                 'correlation_id' => is_string($request->getAttribute('correlation_id')) ? $request->getAttribute('correlation_id') : $key,
                 'causation_id' => is_string($request->getAttribute('causation_id')) ? $request->getAttribute('causation_id') : $key,
                 'source' => 'api',
             ];
             $amount = Arr::int($data, 'amount_minor', -1);
             $currency = Arr::str($data, 'currency', 'VND');
             $paymentId = Arr::requiredParam($request, 'id');
             if ($amount < 0) {
                 // For capture/void, default to stored payment amount if not supplied
                 $stored = $this->paymentStore->find($paymentId);
                 if ($stored !== null) {
                     $amount = Arr::int($stored, 'amount_minor', 0);
                     $currency = Arr::str($stored, 'currency', $currency);
                 }
             }
            // P0: enforce proof for bank_transfer capture on first capture.
            if ($type === 'capture' && $proofRef === null) {
                $stored = $this->paymentStore->find($paymentId);
                $provider = $stored ? Arr::str($stored, 'provider') : '';
                $hasProof = $stored && (Arr::str($stored, 'proof_reference') !== '' || Arr::str($stored, 'proof_url') !== '');
                if (in_array($provider, ['bank_transfer'], true) && !$hasProof) {
                    return $this->error('Proof reference is required for bank transfer capture', 422, ['proof_reference' => ['Required for bank transfer']]);
                }
            }
             $result = $this->payments->execute($paymentId, $type, $amount, $currency, $key, $context);
             // Persist proof reference/url on payment for audit if supplied
             if (($proofRef !== null && $proofRef !== '') || ($proofUrl !== null && $proofUrl !== '')) {
                 try {
                     $row = \App\Models\Payment::query()->where('id', $paymentId)->first();
                     if ($row !== null) {
                         if ($proofRef !== null && $proofRef !== '') $row->setAttribute('proof_reference', trim($proofRef));
                         if ($proofUrl !== null && $proofUrl !== '') $row->setAttribute('proof_url', trim($proofUrl));
                         $row->setAttribute('proof_uploaded_at', date('Y-m-d H:i:s'));
                         $row->save();
                         // also create audit row if not exists for this idempotency
                         $exists = \App\Models\PaymentProof::query()->where('idempotency_key', $key)->first();
                         if ($exists === null) {
                             $orderId = Arr::str($row->toArray(), 'order_id', '');
                             \App\Models\PaymentProof::create([
                                 'id' => \App\Support\Ids::new(),
                                 'payment_id' => $paymentId,
                                 'order_id' => $orderId,
                                 'reference' => trim($proofRef ?? Arr::str($row->toArray(), 'proof_reference', 'capture-proof')),
                                 'file_url' => $proofUrl,
                                 'uploaded_by' => is_array($user) ? Arr::str($user, 'email', 'system') : 'system',
                                 'channel' => 'admin',
                                 'idempotency_key' => $key . ':capture-proof',
                                 'created_at' => date('Y-m-d H:i:s'),
                             ]);
                         }
                     }
                 } catch (\Throwable $e) { /* proof persist must not break capture */ }
             }
             return $this->success($result, 'Payment updated');
         }
         catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error(PublicErrorMessage::from($e->getMessage(), 'Payment could not be completed'), 422); }
    }
}
