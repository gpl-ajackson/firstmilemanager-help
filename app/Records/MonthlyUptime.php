<?php

namespace App\Records;

use Carbon\CarbonImmutable;

/**
 * One month's uptime as Records keeps it (Records::monthlyUptime()): the share of the month's
 * uptime checks that passed, as a percentage (99.95), and the last UK day it counts. The Help
 * Gaps Report shows it as the "Help centre stays up" Reported Measure; until the month is over
 * it is the month so far.
 */
final readonly class MonthlyUptime
{
    /**
     * @param  CarbonImmutable  $month  The month's first day.
     * @param  CarbonImmutable  $through  The last day counted.
     */
    public function __construct(
        public CarbonImmutable $month,
        public float $uptime,
        public CarbonImmutable $through,
    ) {}

    /** The uptime as a reader writes it: "99.95", with no trailing noughts. */
    public function percent(): string
    {
        return rtrim(rtrim(number_format($this->uptime, 4, '.', ''), '0'), '.');
    }

    /** Whether the figure counts the whole month, to its last day. */
    public function isComplete(): bool
    {
        return $this->through->isSameDay($this->month->endOfMonth());
    }
}
