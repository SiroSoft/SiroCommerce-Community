<?php

declare(strict_types=1);

namespace App\Controllers;

use App\CommerceVersion;
use Siro\Core\Controller;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Console server info for the admin status footer.
 */
final class ServerController extends Controller
{
    public function info(Request $request): Response
    {
        $driver = 'unknown';
        try { $driver = (string) Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME); } catch (\Throwable) { }
        return $this->success([
            'status' => 'healthy',
            'version' => CommerceVersion::VERSION,
            'database' => strtolower($driver),
            'uptime' => (int) (microtime(true) - (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))),
        ], 'Server info');
    }
}
