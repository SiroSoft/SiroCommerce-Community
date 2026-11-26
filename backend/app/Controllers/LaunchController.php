<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Launch\Application\DemoSeedService;
use App\Modules\Launch\Application\LaunchStatusService;
use App\Modules\Launch\Domain\LaunchSeedRefused;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/** Operator launch checklist and idempotent demo-data bootstrap. */
final class LaunchController extends Controller
{
    public function __construct(
        private readonly LaunchStatusService $status,
        private readonly DemoSeedService $demoSeed,
    ) {
    }

    public function status(Request $request): Response
    {
        return $this->success($this->status->status(), 'Launch status');
    }

    public function seedDemo(Request $request): Response
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) {
            return $this->error(
                'A valid Idempotency-Key is required',
                422,
                ['Idempotency-Key' => ['Required header: 1-128 characters [A-Za-z0-9._:-]']],
                'VALIDATION_ERROR',
            );
        }

        try {
            $result = $this->demoSeed->seed();
            $createdTotal = array_sum($result['created']);
            return $this->success(
                $result,
                $createdTotal === 0 ? 'Demo data is already present' : 'Demo data seeded',
            );
        } catch (LaunchSeedRefused $error) {
            return $this->error($error->getMessage(), 422, [
                'catalog' => ['Refusing to add demo data to an established catalog.'],
            ], 'SEED_REFUSED');
        } catch (\Throwable $error) {
            return $this->error('Demo seed failed', 500, [], 'INTERNAL_ERROR');
        }
    }
}
