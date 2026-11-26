<?php

declare(strict_types=1);

use Siro\Core\Schedule;

/** @var Schedule $schedule */
$schedule->command('commerce:scheduled --apply')
    ->cron('*/5 * * * *')
    ->withoutOverlapping();
$schedule->command('smart:aggregate')
    ->cron('17 * * * *')
    ->withoutOverlapping();
$schedule->command('metrics:persist')
    ->cron('*/5 * * * *')
    ->withoutOverlapping();
// Keeps the optional Meilisearch catalog index aligned with product changes.
// Product writes are not hooked synchronously; --fresh is run manually when the
// document/settings schema changes (see docs/engineering/SEARCH_MEILI.md).
$schedule->command('search:index-products')
    ->cron('23 3 * * *')
    ->withoutOverlapping();
