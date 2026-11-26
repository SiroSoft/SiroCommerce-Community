<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Console\Commands\ScheduledCommerceOperationsCommand;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class ScheduledOpsController extends Controller
{
    public function run(Request $request): Response
    {
        $apply = $request->queryString('apply', '') === 'true' || $request->queryString('apply', '') === '1';
        $base = dirname(__DIR__, 3);
        $command = new ScheduledCommerceOperationsCommand($base);
        $args = $apply ? ['--apply'] : ['--dry-run'];
        ob_start();
        $exit = $command->run($args);
        $output = ob_get_clean();
        $data = json_decode($output ?: '{}', true);
        if (!is_array($data)) $data = ['output' => $output];
        return $this->success(['exit' => $exit, 'dry_run' => !$apply, 'result' => $data], $apply ? 'Scheduled operations applied' : 'Scheduled dry-run');
    }
}
