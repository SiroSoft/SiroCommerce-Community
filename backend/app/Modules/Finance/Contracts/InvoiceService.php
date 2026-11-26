<?php
declare(strict_types=1);
namespace App\Modules\Finance\Contracts;
interface InvoiceService { /** @return array<string,mixed> */ public function create(string $orderId, string $paymentId, string $idempotencyKey): array; /** @return array<string,mixed>|null */ public function find(string $id): ?array; /** @return array<string,mixed> */ public function issue(string $id, string $idempotencyKey): array; }
