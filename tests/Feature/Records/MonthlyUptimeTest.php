<?php

use App\Records\Records;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/*
 * Monthly uptime (spec #30, user stories 157 and 160; the "Help centre stays up" Reported Measure):
 * Better Stack checks the home page and one Article every five minutes. Every night the help
 * centre reads each monitor's availability from Better Stack for last month and for this month so
 * far, and keeps one figure per month, so the Help Gaps Report (#67) can show a month's uptime.
 * Better Stack is faked at its API.
 */

const UPTIME_MONITORS = ['1001', '1002'];

/**
 * Fakes Better Stack's availability summary: each monitor's availability (percent) for each
 * month, by the month (Y-m) of the from date asked for. Called again, it answers with the new
 * figures from then on.
 *
 * @param  array<string, array<string, float>>  $availability  monitor id => month => percent
 */
function betterStackAnswers(array $availability): void
{
    test()->betterStack = $availability;
    Http::fake(['uptime.betterstack.com/api/v2/monitors/*' => function (Request $request) {
        preg_match('#/monitors/(\d+)/sla#', $request->url(), $monitor);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $percent = test()->betterStack[$monitor[1]][substr((string) $query['from'], 0, 7)] ?? null;

        return $percent === null
            ? Http::response(['errors' => 'Resource not found'], 404)
            : Http::response(['data' => ['id' => $monitor[1], 'type' => 'monitor_sla', 'attributes' => [
                'availability' => $percent,
                'total_downtime' => 0,
                'number_of_incidents' => 0,
                'longest_incident' => 0,
                'average_incident' => 0,
            ]]]);
    }]);
}

/** @return array{0: int, 1: string} the exit code and output */
function recordUptime(): array
{
    $exitCode = Artisan::call('help:record-uptime');

    return [$exitCode, Artisan::output()];
}

/** @return array{0: float, 1: string, 2: bool}|null a month's uptime, the last day it counts and whether the month is complete */
function uptimeOf(string $month): ?array
{
    $uptime = app(Records::class)->monthlyUptime(CarbonImmutable::parse($month));

    return $uptime === null ? null : [$uptime->uptime, $uptime->through->toDateString(), $uptime->isComplete()];
}

beforeEach(function () {
    config(['help.uptime.token' => 'test-uptime-token', 'help.uptime.monitor_ids' => implode(',', UPTIME_MONITORS)]);
    $this->travelTo(CarbonImmutable::parse('2026-12-10 04:30', Records::TIMEZONE));
});

it('keeps last month\'s uptime and this month\'s so far, the mean of the monitors', function () {
    betterStackAnswers([
        '1001' => ['2026-11' => 99.9, '2026-12' => 100.0],
        '1002' => ['2026-11' => 99.7, '2026-12' => 99.5],
    ]);

    [$exitCode, $output] = recordUptime();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('November 2026: 99.8%')
        ->and(uptimeOf('2026-11-01'))->toBe([99.8, '2026-11-30', true])
        ->and(uptimeOf('2026-12-01'))->toBe([99.75, '2026-12-09', false])
        ->and(uptimeOf('2026-10-01'))->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://uptime.betterstack.com/api/v2/monitors/1001/sla?from=2026-11-01&to=2026-11-30'
        && $request->hasHeader('Authorization', 'Bearer test-uptime-token'));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://uptime.betterstack.com/api/v2/monitors/1002/sla?from=2026-12-01&to=2026-12-09');
    Http::assertSentCount(4);
});

it('replaces a month\'s figure each night until the month is over', function () {
    betterStackAnswers(['1001' => ['2026-12' => 99.0, '2026-11' => 100.0], '1002' => ['2026-12' => 99.0, '2026-11' => 100.0]]);
    recordUptime();

    betterStackAnswers(['1001' => ['2026-12' => 98.0, '2026-11' => 100.0], '1002' => ['2026-12' => 99.0, '2026-11' => 100.0]]);
    $this->travelTo(CarbonImmutable::parse('2027-01-01 04:30', Records::TIMEZONE));
    recordUptime();

    expect(uptimeOf('2026-12-01'))->toBe([98.5, '2026-12-31', true])
        ->and(uptimeOf('2026-11-01'))->toBe([100.0, '2026-11-30', true]);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://uptime.betterstack.com/api/v2/monitors/1001/sla?from=2026-12-01&to=2026-12-31');
    Http::assertSentCount(2);
});

it('runs every night', function () {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        fn (Event $event): bool => str_contains((string) $event->command, 'help:record-uptime'),
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('30 4 * * *')
        ->and($events[0]->timezone)->toBe(Records::TIMEZONE);
});

it('fails, naming the setting, when the server has no uptime monitor set up', function (string $setting, string $name) {
    config(["help.uptime.{$setting}" => '']);
    Http::fake();

    [$exitCode, $output] = recordUptime();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain("{$name} is not set")
        ->and(uptimeOf('2026-11-01'))->toBeNull();
    Http::assertNothingSent();
})->with([
    ['token', 'HELP_UPTIME_API_TOKEN'],
    ['monitor_ids', 'HELP_UPTIME_MONITOR_IDS'],
]);

it('keeps nothing from a night Better Stack didn\'t answer for every monitor', function () {
    betterStackAnswers(['1001' => ['2026-11' => 99.0, '2026-12' => 99.0], '1002' => ['2026-12' => 99.0]]);

    [$exitCode, $output] = recordUptime();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Better Stack answered 404 for monitor 1002')
        ->and(uptimeOf('2026-11-01'))->toBeNull()
        ->and(uptimeOf('2026-12-01'))->toBeNull();
});

it('keeps nothing when Better Stack\'s answer has no availability', function () {
    Http::fake(['uptime.betterstack.com/*' => Http::response(['data' => ['attributes' => []]])]);

    [$exitCode, $output] = recordUptime();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('no availability')
        ->and(uptimeOf('2026-11-01'))->toBeNull();
});

it('reads only last month on the first of the month, when this month has no whole day yet', function () {
    betterStackAnswers(['1001' => ['2026-11' => 99.0], '1002' => ['2026-11' => 100.0]]);
    $this->travelTo(CarbonImmutable::parse('2026-12-01 04:30', Records::TIMEZONE));

    expect(recordUptime()[0])->toBe(0)
        ->and(uptimeOf('2026-11-01'))->toBe([99.5, '2026-11-30', true])
        ->and(uptimeOf('2026-12-01'))->toBeNull();
    Http::assertSentCount(2);
});
