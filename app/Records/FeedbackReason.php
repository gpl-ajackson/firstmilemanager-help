<?php

namespace App\Records;

/**
 * The reader's answer to "What was wrong?" after a No (GLOSSARY.md, "Feedback Reason"). The value
 * is what is recorded; wording() is what the reader sees, which never names Stockist on a
 * Client-tier Article, so the Article stays the client's 3PL's.
 */
enum FeedbackReason: string
{
    case DoesntMatch = "It doesn't match what I see";
    case DidntAnswer = "It didn't answer my question";
    case HardToFollow = 'It was hard to follow';
    case SomethingElse = 'Something else';

    public function wording(bool $forClients): string
    {
        return $this === self::DoesntMatch && ! $forClients
            ? "It doesn't match what I see in First Mile Manager"
            : $this->value;
    }
}
