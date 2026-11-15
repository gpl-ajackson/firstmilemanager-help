<?php

namespace App\Records;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * The uptime monitor (spec #30, user story 160; ticket #34): Better Stack Uptime, checking the help
 * home and one Article every five minutes and emailing AJ when either fails. The help centre only
 * reads each monitor's availability from Better Stack's API, through the server's
 * HELP_UPTIME_API_TOKEN, for the monitors HELP_UPTIME_MONITOR_IDS lists.
 *
 * Better Stack's availability summary is GET /api/v2/monitors/<id>/sla?from=<date>&to=<date>,
 * both days included, answering data.attributes.availability as a percentage (99.95) beside
 * downtime figures this class doesn't read.
 */
final readonly class UptimeMonitor
{
    private const API = 'https://uptime.betterstack.com/api/v2';

    /** @param  non-empty-list<string>  $monitors  Better Stack's monitor ids */
    public function __construct(private string $token, private array $monitors) {}

    /**
     * The monitor this server is set up to read.
     *
     * @throws UptimeUnavailable when a setting is missing
     */
    public static function fromConfig(): self
    {
        $token = Config::get('help.uptime.token');
        $ids = Config::get('help.uptime.monitor_ids');
        $monitors = array_values(array_filter(
            array_map(trim(...), explode(',', is_string($ids) ? $ids : '')),
            fn (string $id): bool => $id !== '',
        ));

        $missing = array_keys(array_filter([
            'HELP_UPTIME_API_TOKEN' => ! is_string($token) || $token === '',
            'HELP_UPTIME_MONITOR_IDS' => $monitors === [],
        ]));
        if (! is_string($token) || $token === '' || $monitors === []) {
            throw new UptimeUnavailable(implode(' and ', $missing).(count($missing) === 1 ? ' is' : ' are')
                .' not set on this server, so the uptime monitor can\'t be read.');
        }

        return new self($token, $monitors);
    }

    /**
     * The share of uptime checks that passed between two UK days, both included, as a percentage.
     *
     * Several monitors make one figure as the mean of their availabilities. The measure is checks
     * that passed ÷ all checks (plan, "Success measures"), and every monitor is checked every five
     * minutes, so over the same days each makes the same number of checks and the mean is exactly
     * that share. A monitor added or paused part-way through counts as much as the others; that
     * is close enough for a figure that is only reported.
     *
     * @throws UptimeUnavailable when Better Stack doesn't answer for every monitor
     */
    public function uptime(CarbonInterface $from, CarbonInterface $to): float
    {
        $availabilities = array_map(fn (string $monitor): float => $this->availability($monitor, $from, $to), $this->monitors);

        return array_sum($availabilities) / count($availabilities);
    }

    private function availability(string $monitor, CarbonInterface $from, CarbonInterface $to): float
    {
        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->timeout(20)
                ->get(self::API.'/monitors/'.rawurlencode($monitor).'/sla', ['from' => $from->toDateString(), 'to' => $to->toDateString()]);
        } catch (ConnectionException $e) {
            throw new UptimeUnavailable("Better Stack can't be reached: {$e->getMessage()}");
        }
        if ($response->failed()) {
            throw new UptimeUnavailable("Better Stack answered {$response->status()} for monitor {$monitor}.");
        }
        $availability = $response->json('data.attributes.availability');
        if (! is_numeric($availability)) {
            throw new UptimeUnavailable("Better Stack's answer for monitor {$monitor} has no availability.");
        }

        return (float) $availability;
    }
}
