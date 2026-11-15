<?php

namespace App\Records\Console;

use App\Records\Records;
use App\Records\UptimeMonitor;
use App\Records\UptimeUnavailable;
use Illuminate\Console\Command;

/**
 * Keeps monthly uptime (spec #30, user story 157): every night it reads last month's uptime and
 * this month's so far (to yesterday, the last whole day) from the uptime monitor and keeps one
 * figure per month (Records::recordUptime()), so the Help Gaps Report can show it. Last month is
 * read again each night, so a night the monitor didn't answer is made good the next. Every
 * figure is read before any is kept, so a run either keeps them all or none, and fails saying
 * why. Scheduled daily in routes/console.php.
 */
final class RecordUptimeCommand extends Command
{
    protected $signature = 'help:record-uptime';

    protected $description = 'Read last month\'s and this month\'s uptime from the uptime monitor and keep them';

    public function handle(Records $records): int
    {
        $yesterday = Records::today()->subDay();
        $lastMonth = Records::today()->startOfMonth()->subMonthNoOverflow();
        $months = [[$lastMonth, $lastMonth->endOfMonth()->startOfDay()]];
        if (! $yesterday->isSameMonth($lastMonth)) {
            $months[] = [$yesterday->startOfMonth(), $yesterday];
        }

        try {
            $monitor = UptimeMonitor::fromConfig();
            $figures = array_map(
                fn (array $month): array => [...$month, $monitor->uptime($month[0], $month[1])],
                $months,
            );
        } catch (UptimeUnavailable $e) {
            $this->error($e->getMessage().' No uptime was kept.');

            return self::FAILURE;
        }

        foreach ($figures as [$month, $through, $uptime]) {
            $records->recordUptime($month, $uptime, $through);
            $this->info($month->format('F Y').': '.self::percent($uptime).'% to '.$through->toDateString().'.');
        }

        return self::SUCCESS;
    }

    private static function percent(float $uptime): string
    {
        return rtrim(rtrim(number_format($uptime, 4, '.', ''), '0'), '.');
    }
}
