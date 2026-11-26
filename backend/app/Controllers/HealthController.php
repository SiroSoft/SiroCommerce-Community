<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Shared\Hooks\HookBus;
use App\Modules\Shared\Hooks\HookCatalog;
use Siro\Core\Controller;
use Siro\Core\Database;
use Siro\Core\Metrics;
use Siro\Core\Request;
use App\Support\ProviderReadiness;
use App\Modules\Search\Contracts\SearchAdapter;

final class HealthController extends Controller
{
    public function __construct(private readonly ?SearchAdapter $search = null) {}

    public function live(): \Siro\Core\Response
    {
        return $this->success(['status' => 'live'], 'OK');
    }

    /**
     * Service readiness: can this instance serve traffic right now?
     * Provider production configuration is intentionally NOT part of this
     * signal. It is a release gate, reported separately and in the payload as
     * `release_ready`, so a sandboxed provider never masks a real outage and a
     * green instance never hides an unfinished provider rollout.
     */
    public function ready(Request $request): \Siro\Core\Response
    {
        $checks = $this->readinessChecks();
        $serviceChecks = array_intersect_key($checks, array_flip(['database', 'migrations', 'queue', 'outbox', 'dead_letters', 'payments', 'search']));
        $ready = !in_array(false, $serviceChecks, true);
        $providers = (bool) $checks['providers'];
        return $this->success([
            'status' => $ready ? 'ready' : 'not_ready',
            'checks' => $serviceChecks,
            'release_ready' => $ready && $providers,
            'providers' => ['configured' => $providers, 'missing' => $checks['provider_missing']],
        ], $ready ? 'Ready' : 'Not ready', $ready ? 200 : 503, [
            'observed_at' => date('c'),
            'evidence' => 'configuration_and_dependency_probes',
        ]);
    }

    /** Provider rollout gate: configuration only, never live calls, never secrets. */
    public function providerReadiness(Request $request): \Siro\Core\Response
    {
        $provider = ProviderReadiness::check();
        return $this->success([
            'status' => $provider['ready'] === true ? 'configured' : 'not_configured',
            'configured' => $provider['ready'],
            'missing' => $provider['missing'],
            'payment' => $provider['payment'],
            'shipping' => $provider['shipping'],
            'transactional_email' => $provider['transactional_email'],
            'blocker' => $provider['blocker'],
        ], 'Provider configuration');
    }

    public function detailedReadiness(Request $request): \Siro\Core\Response
    {
        $checks = $this->readinessChecks();
        $ready = !in_array(false, $checks, true);
        return $this->success(['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks], $ready ? 'Ready' : 'Not ready', $ready ? 200 : 503, [
            'observed_at' => date('c'),
            'evidence' => 'configuration_and_dependency_probes',
        ]);
    }

    /** @return array<string, mixed> */
    private function readinessChecks(): array
    {
        $provider = ProviderReadiness::check();
        $searchStatus = $this->search?->status() ?? ['ready' => false, 'stale' => true, 'lag_seconds' => null];
        $checks = ['database' => $this->databaseReady(), 'migrations' => false, 'queue' => false, 'outbox' => false, 'dead_letters' => false, 'payments' => false, 'search' => ($searchStatus['ready'] ?? false) === true && ($searchStatus['stale'] ?? true) === false, 'search_index' => $searchStatus, 'providers' => $provider['ready'], 'provider_missing' => $provider['missing']];
        try {
            $db = Database::connection();
            $checks['migrations'] = $this->probe($db, "SELECT COUNT(*) FROM migrations");
            $checks['queue'] = $this->probe($db, "SELECT COUNT(*) FROM commerce_jobs");
            $checks['outbox'] = $this->probe($db, "SELECT COUNT(*) FROM outbox_messages WHERE state = 'dead'", 0);
            $checks['dead_letters'] = $this->probe($db, "SELECT COUNT(*) FROM commerce_dead_letters WHERE resolved_at IS NULL", 0);
            $checks['payments'] = $this->probe($db, "SELECT COUNT(*) FROM payment_operations WHERE state = 'unknown'", 0);
        } catch (\Throwable) {
            // A connection failure means every database-backed dependency is down.
        }
        return $checks;
    }

    private function probe(\PDO $db, string $sql, ?int $expected = null): bool
    {
        try {
            $value = $this->scalar($db, $sql);
            return $expected === null || $value === $expected;
        } catch (\Throwable) {
            return false;
        }
    }

    private function scalar(\PDO $db, string $sql): int
    {
        $statement = $db->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('Readiness query failed');
        }
        return (int) $statement->fetchColumn();
    }

    public function metrics(): \Siro\Core\Response
    {
        Metrics::init('sirocommerce', true);
        return $this->success(Metrics::export(), 'Metrics');
    }

    public function hooks(): \Siro\Core\Response
    {
        return $this->success(['catalog' => HookCatalog::names(), 'registered_handlers' => (new HookBus())->handlerCounts()], 'Hooks');
    }

    public function observability(): \Siro\Core\Response
    {
        Metrics::init('sirocommerce', true);
        return $this->success(['hooks' => HookCatalog::names(), 'registered_handlers' => (new HookBus())->handlerCounts(), 'metrics' => Metrics::export()], 'OK');
    }

    private function databaseReady(): bool
    {
        try {
            Database::connection()->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
