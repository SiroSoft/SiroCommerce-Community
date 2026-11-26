<?php
declare(strict_types=1);
namespace Tests\Api;
use PHPUnit\Framework\TestCase;
final class SearchRouteEvidenceTest extends TestCase
{
    public function testSearchOperationsHaveExactAdminCapabilityMiddleware(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/api.php');
        foreach (["get('/api/v2/admin/search'", "get('/api/v2/admin/search/status'", "post('/api/v2/admin/search/reindex'"] as $index => $route) {
            $position = strpos($routes, $route);
            self::assertNotFalse($position);
            $line = strtok(substr($routes, $position), "\n");
            if ($index === 0) {
                self::assertStringContainsString('$searchStaff', (string) $line);
                self::assertStringContainsString("'auth:admin,owner,support,warehouse,finance'", $routes);
            } else {
                self::assertStringContainsString("'cap:search'", (string) $line);
                self::assertStringContainsString('auth:', (string) $line);
            }
        }
    }
}
