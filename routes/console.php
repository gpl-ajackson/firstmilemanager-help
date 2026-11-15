<?php

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| Schedules go here. The server's scheduler runs them:
| Forge's scheduler runs `php artisan schedule:run` every minute (docs/rebuilding-the-server.md).
| Artisan commands live in each module's own Console folder (app/<Module>/Console).
|
*/

use App\Records\Records;
use App\Reports\ReportMonth;
use Illuminate\Support\Facades\Schedule;

// Rate-limit keys are held only for minutes (spec #30, Further Note 4).
Schedule::command('help:forget-rate-limits')->everyFiveMinutes();

// "Doesn't match" votes reach their Article Owners the same day (spec #30, user stories 70 to 72);
// a vote GitHub didn't take is sent on a later run.
Schedule::command('help:doesnt-match-issues')->everyFiveMinutes()->withoutOverlapping();

// Records are kept no longer than rule 11 allows (spec #30, "Keeping"): every night, UK time.
Schedule::command('help:prune-records')->dailyAt('03:30')->timezone(Records::TIMEZONE);

// Readers' comments copied into "doesn't match" issues are kept no longer than the comments
// themselves (rule 11): every night, after the pruning, each issue opened over six months ago goes.
Schedule::command('help:delete-old-doesnt-match-issues')->dailyAt('03:45')->timezone(Records::TIMEZONE);

// A lost server costs at most a day of records (spec #30, user story 161): every night, after the
// pruning, so a record pruned that night isn't kept in the backup; each backup is deleted 14 days on.
Schedule::command('help:back-up-records')->dailyAt('04:00')->timezone(Records::TIMEZONE);

// Monthly uptime for the Help Gaps Report (spec #30, user story 157): last month and this month so
// far, every night, so a month is complete the night after it ends.
Schedule::command('help:record-uptime')->dailyAt('04:30')->timezone(Records::TIMEZONE);

// The Help Gaps Report (spec #30, user stories 150 to 158): on the first working day of the month,
// after the night's uptime run has made last month's uptime complete. It links the month's Help
// Drift Report, which a workflow raises from 06:00 UTC, so it is tried on the hour from 08:00 and
// waits for that issue until the 16:00 run, which raises it without. Later runs leave it be.
Schedule::command('help:gaps-report --wait-until=16:00')
    ->cron('0 8-16 * * *')
    ->timezone(Records::TIMEZONE)
    ->when(fn (): bool => ReportMonth::isReportDay(Records::today()))
    ->withoutOverlapping();
