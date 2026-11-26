<?php
declare(strict_types=1);
namespace App\Modules\Finance\Contracts;
interface CreditMemoService { /** @return array<string,mixed> */ public function create(string $invoiceId, int $amountMinor, string $reason, string $idempotencyKey): array; /** @return array<string,mixed>|null */ public function find(string $id): ?array; }
