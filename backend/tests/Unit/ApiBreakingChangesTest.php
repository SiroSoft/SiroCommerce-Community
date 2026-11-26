<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OpenApiBreakingChanges;
use PHPUnit\Framework\TestCase;

final class ApiBreakingChangesTest extends TestCase
{
    public function testAdditiveChangesPass(): void
    {
        $baseline = $this->document();
        $current = $baseline;
        $current['paths']['/orders']['get']['responses']['202'] = ['description' => 'Accepted'];
        $current['components']['schemas']['Order']['properties']['note'] = ['type' => 'string'];

        self::assertSame([], OpenApiBreakingChanges::detect($baseline, $current));
    }

    public function testConsumerBreakingChangesAreReportedDeterministically(): void
    {
        $baseline = $this->document();
        $current = $baseline;
        unset($current['paths']['/orders']['get']['responses']['404']);
        $current['paths']['/orders']['get']['parameters'][0]['required'] = true;
        $current['components']['schemas']['Order']['required'][] = 'status';

        self::assertSame([
            'made parameter query:page required in GET /orders',
            'removed response 404 from GET /orders',
            'added required property schema Order.status',
        ], OpenApiBreakingChanges::detect($baseline, $current));
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        return [
            'paths' => [
                '/orders' => [
                    'get' => [
                        'operationId' => 'list_orders',
                        'parameters' => [['name' => 'page', 'in' => 'query', 'required' => false]],
                        'responses' => ['200' => ['description' => 'OK'], '404' => ['description' => 'Missing']],
                    ],
                ],
            ],
            'components' => ['schemas' => [
                'Order' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'string']]],
            ]],
        ];
    }
}
