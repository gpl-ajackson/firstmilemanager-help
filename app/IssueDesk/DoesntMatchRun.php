<?php

namespace App\IssueDesk;

/**
 * What one run of the issue desk did with the "doesn't match" votes waiting: a line for each vote
 * sent (an issue opened or a comment added), how many still wait, and why, when GitHub couldn't
 * be used. Waiting votes are sent on the next run.
 */
final readonly class DoesntMatchRun
{
    /** @param  list<string>  $sent */
    public function __construct(
        public array $sent,
        public int $waiting,
        public ?string $problem = null,
    ) {}
}
