<?php

namespace App\IssueDesk;

/** An issue GitHub has just opened: its number, to comment on, and its address, for the log. */
final readonly class OpenedIssue
{
    public function __construct(public int $number, public string $address) {}
}
