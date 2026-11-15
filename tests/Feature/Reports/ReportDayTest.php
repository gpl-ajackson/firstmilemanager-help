<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

/*
 * The monthly reports are raised on the first working day of each month, UK time (spec #30,
 * Further Notes 3): Monday to Friday, less England and Wales bank holidays. The Help Drift
 * Report's workflow runs on the 1st to the 7th and asks help:report-day first.
 */

function reportDay(?string $date = null): string
{
    Artisan::call('help:report-day', $date === null ? [] : ['--date' => $date]);

    return trim(Artisan::output());
}

it('says yes on the first working day of the month, and which month the reports are about', function () {
    expect(reportDay('2026-12-01'))->toBe('yes: Tuesday 1 December 2026 is the first working day of December 2026, so the reports about November 2026 are raised today.');
});

it('passes over weekends and bank holidays', function (string $day, string $firstWorkingDay) {
    expect(reportDay($day))->toStartWith('no: ')
        ->and(reportDay($firstWorkingDay))->toStartWith('yes: ');
})->with([
    'a Sunday the 1st' => ['2026-11-01', '2026-11-02'],
    'New Year\'s Day on a Friday' => ['2027-01-01', '2027-01-04'],
    'New Year\'s Day on a Saturday, its substitute the Monday' => ['2022-01-03', '2022-01-04'],
    'Easter Monday on the 1st' => ['2024-04-01', '2024-04-02'],
    'the May bank holiday after a weekend 1st' => ['2027-05-03', '2027-05-04'],
]);

it('asks about today, UK time, when no date is given', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:30', 'Europe/London'));

    expect(reportDay())->toStartWith('yes: Tuesday 1 December 2026');
});
