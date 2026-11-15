<?php

namespace App\Reports\Console;

use App\Records\Records;
use App\Reports\ReportMonth;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Whether today (UK) is the day the monthly reports are raised: the first working day of the
 * month. GitHub's schedule can't say "first working day", so the Help Drift Report's workflow
 * runs on the 1st to the 7th and asks this first. Prints one line starting "yes" or "no".
 */
final class ReportDayCommand extends Command
{
    protected $signature = 'help:report-day {--date= : The day to ask about, YYYY-MM-DD (default: today, UK time)}';

    protected $description = 'Say whether today is the first working day of the month, when the monthly reports are raised';

    public function handle(): int
    {
        $date = $this->option('date');
        if (is_string($date) && $date !== '' && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
            $this->error("--date is a day written YYYY-MM-DD, not \"{$date}\".");

            return self::INVALID;
        }
        $day = is_string($date) && $date !== '' ? CarbonImmutable::parse($date, Records::TIMEZONE) : Records::today();
        $first = ReportMonth::firstWorkingDay($day);

        $this->line(ReportMonth::isReportDay($day)
            ? "yes: {$day->format('l j F Y')} is the first working day of {$day->format('F Y')}, so the reports about ".ReportMonth::endedBefore($day)->name().' are raised today.'
            : "no: the first working day of {$day->format('F Y')} is {$first->format('l j F Y')}, not {$day->format('l j F Y')}.");

        return self::SUCCESS;
    }
}
