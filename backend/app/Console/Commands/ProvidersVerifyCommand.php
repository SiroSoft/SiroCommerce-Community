<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Launch\Application\ProviderProbeService;
use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;

/**
 * Read-only provider verification shared with GET /api/admin/providers/verify.
 *
 * The table never includes credential values; only names, booleans, and short
 * reachability details leave the probe service.
 */
final class ProvidersVerifyCommand implements CommandInterface
{
    use CommandSupport;

    public string $signature = 'providers:verify';
    public string $description = 'Probe configured providers read-only and report reachability';

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $result = (new ProviderProbeService())->verify();
        $rows = [];
        foreach ($result['providers'] as $provider) {
            $rows[] = [
                $provider['name'],
                $provider['type'],
                $provider['configured'] ? 'yes' : 'no',
                $provider['reachable'] ? 'yes' : 'no',
                $provider['detail'],
            ];
        }
        $this->table(['Provider', 'Type', 'Configured', 'Reachable', 'Detail'], $rows);
        $this->write($result['certified'] ? 'Certified: YES' : 'Certified: NO');

        return $result['certified'] ? 0 : 1;
    }
}
