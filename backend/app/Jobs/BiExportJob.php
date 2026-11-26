<?php
declare(strict_types=1);
namespace App\Jobs;
use App\Modules\Platform\Infrastructure\BiExportService;
use App\Support\Arr;
use Siro\Core\Container;
use Siro\Core\QueueInterface;
final class BiExportJob implements QueueInterface
{
    public int $maxAttempts = 5;
    /** @param array<string, mixed> $data */
    public function handle(array $data = []): void
    {
        $service = Container::getInstance()->make(BiExportService::class);
        if (!$service instanceof BiExportService) throw new \RuntimeException('BI export service binding is invalid');
        $service->process(Arr::requiredStr($data, 'export_id'));
    }
}
