<?php

declare(strict_types=1);

namespace Tests\Workflow;

use Tests\Support\OwnedContentRoutes;
use Tests\TestCase;

final class ContentRouteEvidenceTest extends TestCase
{
    public function testOwnedContentOperationsAreExact(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/api.php');
        foreach (OwnedContentRoutes::all() as $operation) {
            [$method, $path] = explode(' ', $operation, 2);
            self::assertStringContainsString("->router->" . strtolower($method) . "('" . $path . "'", $source, $operation);
        }
    }
}
