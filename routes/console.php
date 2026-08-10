<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| §12's swept triggers: "pending" (due soon) and "overdue".
|
| Registering the command here is what makes it run. Writing the command and
| never scheduling it is a silent no-op -- the sweep would never fire even on a
| host that HAS cron, which is the worst of both worlds.
|
| Deployment needs exactly one crontab line:
|
|   * * * * * cd /path/to/cicto && php artisan schedule:run >> /dev/null 2>&1
|
| If the host has no cron, the Overdue filter, the row badges and the dashboard
| counts all stay correct anyway, because overdue is a live query predicate
| rather than a stored flag. The only thing lost is the push notification --
| see client question B2 and docs/implementation/00-architecture.md §10.
*/
Schedule::command('cicto:notify-deadlines')
    ->dailyAt('08:00')
    ->timezone(config('app.timezone'))
    // Overlap protection matters on shared hosting, where a slow sweep can
    // still be running when the next one is due.
    ->withoutOverlapping(30)
    ->onOneServer();

/*
| §15: what turns "tampering is detectable" into "tampering is detected".
| Nobody re-opens a six-month-old certificate by hand.
*/
Schedule::command('cicto:verify-signatures --quiet-ok')
    ->dailyAt('02:30')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(30)
    ->onOneServer();

/*
| §22. Nightly database backup.
|
| Depends entirely on the host having cron. Without it this never runs and
| "automated regular backups" is not delivered -- see client question B2.
*/
Schedule::command('cicto:backup')
    ->dailyAt('01:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(60)
    ->onOneServer();
