<?php

namespace App\Reports;

use App\Records\Records;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The month a monthly report is about, in UK time (Records::TIMEZONE), and the day it is
 * raised: the first working day of the next month (spec #30, Further Notes 3). The Help Drift
 * Report raised on Tuesday 1 December 2026 is "Help drift: November 2026", and the Help Gaps
 * Report raised that day is about November too, so it can link it.
 *
 * Working days are Monday to Friday, less England and Wales bank holidays (New Year's Day,
 * Good Friday, Easter Monday, the May, spring and summer bank holidays, Christmas Day and
 * Boxing Day, with substitute days). A bank holiday added for one year only (a coronation, a
 * jubilee) isn't known here; run the report by hand that month if it falls on the 1st to the 3rd.
 */
final readonly class ReportMonth
{
    /** @param  CarbonImmutable  $first  the month's first day, at midnight UK time */
    private function __construct(public CarbonImmutable $first) {}

    /** A month written YYYY-MM. */
    public static function of(string $month): self
    {
        if (preg_match('/\A(\d{4})-(0[1-9]|1[0-2])\z/', $month, $parts) !== 1) {
            throw new InvalidArgumentException("\"{$month}\" isn't a month written YYYY-MM, such as 2026-11.");
        }

        return new self(CarbonImmutable::create((int) $parts[1], (int) $parts[2], 1, 0, 0, 0, Records::TIMEZONE));
    }

    /** The month that ended before $day's month began: what a report raised on $day is about. */
    public static function endedBefore(CarbonImmutable $day): self
    {
        $day = $day->setTimezone(Records::TIMEZONE);

        return new self(CarbonImmutable::create($day->year, $day->month, 1, 0, 0, 0, Records::TIMEZONE)->subMonthNoOverflow());
    }

    /** "2026-11", as the reports' markers write it. */
    public function key(): string
    {
        return $this->first->format('Y-m');
    }

    /** "November 2026" */
    public function name(): string
    {
        return $this->first->format('F Y');
    }

    /** The month before, which the Help Gaps Report sets beside this one. */
    public function previous(): self
    {
        return new self($this->first->subMonthNoOverflow());
    }

    /** The same month a year earlier, which the Help Gaps Report sets beside this one too. */
    public function yearEarlier(): self
    {
        return new self($this->first->subYearNoOverflow());
    }

    /** The month's last moment, UK time. */
    public function end(): CarbonImmutable
    {
        return $this->first->endOfMonth();
    }

    /** The day the report about this month is raised: the next month's first working day. */
    public function reportDay(): CarbonImmutable
    {
        return self::firstWorkingDay($this->first->addMonthNoOverflow());
    }

    /** Whether $day (UK) is the first working day of its month, the day the monthly reports are raised. */
    public static function isReportDay(CarbonImmutable $day): bool
    {
        $day = $day->setTimezone(Records::TIMEZONE)->startOfDay();

        return self::firstWorkingDay($day)->isSameDay($day);
    }

    /** The first working day of $day's month. */
    public static function firstWorkingDay(CarbonImmutable $day): CarbonImmutable
    {
        $day = $day->setTimezone(Records::TIMEZONE)->startOfMonth();
        while ($day->isWeekend() || self::isBankHoliday($day)) {
            $day = $day->addDay();
        }

        return $day;
    }

    /** An England and Wales bank holiday (the regular ones and their substitute days). */
    public static function isBankHoliday(CarbonImmutable $day): bool
    {
        $year = $day->year;
        $date = $day->format('m-d');
        $easter = self::easterSunday($year);
        $holidays = [
            self::substitute(CarbonImmutable::create($year, 1, 1, 0, 0, 0, Records::TIMEZONE))->format('m-d'),
            $easter->subDays(2)->format('m-d'),
            $easter->addDay()->format('m-d'),
            CarbonImmutable::create($year, 5, 1, 0, 0, 0, Records::TIMEZONE)->firstOfMonth(CarbonImmutable::MONDAY)->format('m-d'),
            CarbonImmutable::create($year, 5, 1, 0, 0, 0, Records::TIMEZONE)->lastOfMonth(CarbonImmutable::MONDAY)->format('m-d'),
            CarbonImmutable::create($year, 8, 1, 0, 0, 0, Records::TIMEZONE)->lastOfMonth(CarbonImmutable::MONDAY)->format('m-d'),
        ];
        // Christmas Day and Boxing Day: a weekend one moves to the next weekday not already taken.
        $christmas = CarbonImmutable::create($year, 12, 25, 0, 0, 0, Records::TIMEZONE);
        $taken = [];
        foreach ([$christmas, $christmas->addDay()] as $holiday) {
            while ($holiday->isWeekend() || in_array($holiday->format('m-d'), $taken, true)) {
                $holiday = $holiday->addDay();
            }
            $taken[] = $holiday->format('m-d');
        }

        return in_array($date, [...$holidays, ...$taken], true);
    }

    /** A weekend holiday's substitute: the Monday after. */
    private static function substitute(CarbonImmutable $holiday): CarbonImmutable
    {
        return $holiday->isWeekend() ? $holiday->next(CarbonImmutable::MONDAY) : $holiday;
    }

    /** Easter Sunday in the Gregorian calendar (the anonymous Gregorian algorithm). */
    private static function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, Records::TIMEZONE);
    }
}
