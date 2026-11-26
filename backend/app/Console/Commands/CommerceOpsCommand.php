<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Providers\CommerceServiceProvider;
use App\Modules\Platform\Infrastructure\OutboxDispatcher;
use App\Modules\Platform\Infrastructure\V2SideEffectRepository;
use App\Modules\Dev\Application\DevService;
use App\Modules\Payment\Application\PaymentReconciliationService;
use App\Modules\Payment\Contracts\PaymentReconciliationProvider;
use Siro\Core\App;
use Siro\Core\Audit;
use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;
use Siro\Core\Container;
use Siro\Core\Database;
use Siro\Core\Metrics;

/** Operational control plane for commerce side effects and recovery. */
final class CommerceOpsCommand implements CommandInterface
{
    use CommandSupport;

    public string $signature = 'commerce:ops';
    public string $description = 'Inspect, dispatch and recover commerce side effects';

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $operation = $args[0] ?? 'readiness';
        $options = array_slice($args, 1);
        $app = new App($this->basePath);
        $app->boot();
        (new CommerceServiceProvider())->register($app);

        try {
            $result = $this->runOperation($operation, $options);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $this->writeResult($result);
        return 0;
    }

    /**
     * @param array<int, string> $options
     * @return array<string, mixed>
     */
    private function runOperation(string $operation, array $options): array
    {
        return match ($operation) {
            'outbox' => $this->outbox($options),
            'jobs' => $this->jobs($options),
            'payments' => $this->payments($options),
            'inventory' => $this->inventory($options),
            'webhooks' => $this->webhooks($options),
            'orders' => $this->orders($options),
            'metrics' => $this->metrics(),
            'readiness', 'dr' => $this->readiness($operation),
            default => throw new \InvalidArgumentException('Unknown commerce operation: ' . $operation),
        };
    }

    /**
     * @param array<int, string> $args
     * @return array<string, mixed>
     */
    private function outbox(array $args): array
    {
        $action = $args[0] ?? 'status';
        $rows = $this->rows('SELECT id, event_id, topic, state, attempts, available_at, published_at, last_error FROM outbox_messages ORDER BY available_at ASC');
        if ($action === 'status') return ['action' => $action, 'messages' => $rows];

        if ($action === 'replay') {
            $id = $this->requiredOption($args, '--id=');
            $changed = $this->executeCount('UPDATE outbox_messages SET state = ?, available_at = ?, locked_at = NULL, last_error = NULL WHERE id = ? AND state IN (?, ?)', ['pending', date('Y-m-d H:i:s'), $id, 'failed', 'dead']);
            if ($changed === 0) throw new \InvalidArgumentException('Outbox message is missing or not failed: ' . $id);
            $this->audit('commerce.outbox.replay', ['id' => $id]);
            return ['action' => $action, 'id' => $id, 'state' => 'pending'];
        }

        if ($action === 'dispatch') {
            $limit = $this->intOption($args, '--limit=', 50);
            $dryRun = !$this->has($args, '--publish');
            $result = (new OutboxDispatcher())->dispatch($limit, !$dryRun);
            return ['action' => $action, 'dry_run' => $dryRun] + $result;
        }

        throw new \InvalidArgumentException('Outbox action must be status, dispatch or replay');
    }

    /**
     * @param array<int, string> $args
     * @return array<string, mixed>
     */
    private function jobs(array $args): array
    {
        $action = $args[0] ?? 'status';
        if ($action === 'status') {
            return ['action' => $action, 'jobs' => $this->rows('SELECT id, queue, name, state, attempts, max_attempts, available_at, last_error FROM commerce_jobs ORDER BY available_at ASC')];
        }
        $id = $this->requiredOption($args, '--id=');
        if ($action === 'retry') {
            Database::transaction(function () use ($id): void {
                $changed = $this->executeCount('UPDATE commerce_jobs SET state = ?, available_at = ?, locked_at = NULL, worker_id = NULL, last_error = NULL WHERE id = ? AND state = ?', ['queued', date('Y-m-d H:i:s'), $id, 'dead']);
                if ($changed !== 1) throw new \InvalidArgumentException('Only dead commerce jobs can be retried: ' . $id);
                $this->execute('UPDATE commerce_dead_letters SET resolved_at = ? WHERE job_id = ? AND resolved_at IS NULL', [date('Y-m-d H:i:s'), $id]);
            });
            $this->audit('commerce.job.retry', ['id' => $id]);
            return ['action' => $action, 'id' => $id, 'state' => 'queued'];
        }
        if ($action === 'dead-letter') {
            (new V2SideEffectRepository())->deadLetter($id, $this->option($args, '--reason=', 'Manually dead-lettered'));
            $this->audit('commerce.job.dead_letter', ['id' => $id]);
            return ['action' => $action, 'id' => $id, 'state' => 'dead'];
        }
        throw new \InvalidArgumentException('Jobs action must be status, retry or dead-letter');
    }

    /**
     * @param array<int, string> $args
     * @return array<string, mixed>
     */
    private function payments(array $args): array
    {
        $service = Container::getInstance()->make(PaymentReconciliationService::class);
        $provider = Container::getInstance()->make(PaymentReconciliationProvider::class);
        if (!$service instanceof PaymentReconciliationService || !$provider instanceof PaymentReconciliationProvider) throw new \RuntimeException('Payment reconciliation bindings unavailable');
        return ['action' => 'reconcile'] + $service->reconcile($provider, $this->has($args, '--apply'));
    }

    /**
     * @param array<int, string> $args
     * @return array<string, mixed>
     */
    private function inventory(array $args): array
    {
        $dryRun = !$this->has($args, '--apply');
        $container = Container::getInstance();
        $recovery = $container->make(DevService::class);
        if (!$recovery instanceof DevService) throw new \RuntimeException('Recovery service unavailable');
        $report = $recovery->releaseExpired($dryRun, $this->option($args, '--actor=', 'cli'));
        if (!$dryRun) $this->audit('commerce.inventory.repair', $report);
        return $report;
    }

    /**
     * @param array<int, string> $args
     * @return array<string, mixed>
     */
    private function webhooks(array $args): array
    {
        $rows = $this->rows('SELECT id, provider, event_id, signature_ok, state, received_at, processed_at, last_error FROM payment_webhook_events ORDER BY received_at DESC');
        return ['action' => 'replay', 'dry_run' => true, 'requires_provider_adapter' => true, 'events' => $rows];
    }

    /**
     * @param array<int, string> $args
     * @return array<string, mixed>
     */
    private function orders(array $args): array
    {
        $id = $this->requiredOption($args, '--id=');
        $dryRun = !$this->has($args, '--apply');
        $recovery = Container::getInstance()->make(DevService::class);
        if (!$recovery instanceof DevService) throw new \RuntimeException('Recovery service unavailable');
        $report = $recovery->rescue($id, $this->option($args, '--actor=', 'cli'), $dryRun);
        if (!$dryRun) $this->audit('commerce.order.recovery', $report);
        return $report;
    }

    /** @return array<string, mixed> */
    private function metrics(): array
    {
        Metrics::init('sirocommerce', true);
        Metrics::persistNow();
        return ['action' => 'snapshot', 'metrics' => Metrics::export()];
    }

    /** @return array<string, mixed> */
    private function readiness(string $action): array
    {
        $checks = [];
        foreach (['outbox_messages', 'commerce_jobs', 'commerce_dead_letters', 'payment_operations', 'payment_webhook_events'] as $table) {
            try {
                $row = $this->rows('SELECT COUNT(*) AS count FROM ' . $table);
                $count = $row[0]['count'] ?? 0;
                $checks[$table] = is_numeric($count) ? (int) $count : 0;
            } catch (\Throwable) {
                $checks[$table] = null;
            }
        }
        $checks['outbox_ready'] = $this->scalar('SELECT COUNT(*) FROM outbox_messages WHERE state = ? AND (available_at IS NULL OR available_at <= ?)', ['pending', date('Y-m-d H:i:s')]);
        $checks['outbox_dead'] = $this->scalar('SELECT COUNT(*) FROM outbox_messages WHERE state = ?', ['dead']);
        $checks['jobs_stale'] = $this->scalar('SELECT COUNT(*) FROM commerce_jobs WHERE state = ? AND locked_at IS NOT NULL AND locked_at <= ?', ['running', date('Y-m-d H:i:s')]);
        $findings = [];
        try {
            $doctor = Container::getInstance()->make(DevService::class);
            if (!$doctor instanceof DevService) throw new \RuntimeException('Doctor service unavailable');
            $findings = $doctor->diagnose('all');
        } catch (\Throwable $e) { $findings[] = ['level' => 'critical', 'finding' => $e->getMessage()]; }
        return ['action' => $action, 'checks' => $checks, 'doctor_findings' => $findings, 'audit' => Audit::verify()];
    }

    /** @param array<int, string> $args */
    private function has(array $args, string $option): bool { return in_array($option, $args, true); }

    /** @param array<int, string> $args */
    private function option(array $args, string $prefix, string $default): string { foreach ($args as $arg) if (str_starts_with($arg, $prefix)) return substr($arg, strlen($prefix)); return $default; }

    /** @param array<int, string> $args */
    private function requiredOption(array $args, string $prefix): string { $value = $this->option($args, $prefix, ''); if ($value === '') throw new \InvalidArgumentException('Missing ' . $prefix . '<value>'); return $value; }

    /** @param array<int, string> $args */
    private function intOption(array $args, string $prefix, int $default): int { return max(1, (int) $this->option($args, $prefix, (string) $default)); }

    /**
     * @param array<int, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $sql, array $bindings = []): array
    {
        $statement = Database::connection()->prepare($sql);
        $statement->execute($bindings);
        $rawRows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        $rows = [];
        foreach ($rawRows as $row) {
            if (is_array($row)) {
                $normalized = [];
                foreach ($row as $key => $value) {
                    if (is_string($key)) $normalized[$key] = $value;
                }
                $rows[] = $normalized;
            }
        }
        return $rows;
    }

    /** @param array<int, mixed> $bindings */
    private function execute(string $sql, array $bindings = []): void { $statement = Database::connection()->prepare($sql); $statement->execute($bindings); }

    /** @param array<int,mixed> $bindings */
    private function executeCount(string $sql, array $bindings = []): int { $statement = Database::connection()->prepare($sql); $statement->execute($bindings); return $statement->rowCount(); }

    /** @param array<int,mixed> $bindings */
    private function scalar(string $sql, array $bindings = []): int { $statement = Database::connection()->prepare($sql); $statement->execute($bindings); return (int) $statement->fetchColumn(); }

    /** @param array<string, mixed> $context */
    private function audit(string $action, array $context): void { Audit::setBasePath($this->basePath); Audit::log($action, $context); }

    /** @param array<string, mixed> $result */
    private function writeResult(array $result): void { $this->write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
}
