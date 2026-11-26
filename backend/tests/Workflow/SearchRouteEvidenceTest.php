<?php
declare(strict_types=1);
namespace Tests\Workflow;
use PHPUnit\Framework\TestCase;
final class SearchRouteEvidenceTest extends TestCase
{
    public function testSearchOperationalWorkflowKeepsReadinessBeforeReindex(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/api.php');
        $status = strpos($routes, "get('/api/v2/admin/search/status'");
        $reindex = strpos($routes, "post('/api/v2/admin/search/reindex'");
        self::assertIsInt($status);
        self::assertIsInt($reindex);
        self::assertLessThan($reindex, $status);
    }
}
