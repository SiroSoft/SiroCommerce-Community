<?php
declare(strict_types=1);
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
final class SearchRouteEvidenceTest extends TestCase
{
    public function testSearchRoutesDeclareSafeBoundaries(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/api.php');
        self::assertStringContainsString("get('/api/v2/admin/search'", $routes);
        self::assertStringContainsString("get('/api/v2/admin/search/status'", $routes);
        self::assertStringContainsString("post('/api/v2/admin/search/reindex'", $routes);
        self::assertSame(3, preg_match_all("~'/api/v2/admin/search(?:/status|/reindex)?'~", $routes));
    }
}
