<?php

namespace App\IssueDesk;

/**
 * What one run of deleting old "doesn't match" issues did: a line for each issue deleted, and why
 * it stopped, when GitHub couldn't be used. Issues left behind are deleted on the next night's run.
 */
final readonly class DeletedIssuesRun
{
    /** @param  list<string>  $deleted */
    public function __construct(
        public array $deleted,
        public ?string $problem = null,
    ) {}
}
