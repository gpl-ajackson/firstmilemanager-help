<?php

namespace App\Records;

use Carbon\CarbonImmutable;

/**
 * A No vote whose Feedback Reason is "It doesn't match what I see", waiting for the issue desk to
 * send it to the Article Owner. The reference is the vote's own, so the desk can mark it sent
 * (Records::sentToIssue()); it never leaves the server.
 */
final readonly class DoesntMatchVote
{
    public function __construct(
        public string $reference,
        public string $article,
        public ?string $comment,
        public CarbonImmutable $checkedOn,
        public CarbonImmutable $day,
    ) {}
}
