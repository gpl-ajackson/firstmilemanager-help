<?php

namespace App\Releases\Check;

/** A support-site pull request a released change links as its Help Proposal, as GitHub has it now. */
final readonly class HelpProposal
{
    public const string MERGED = 'merged';

    public const string OPEN = 'open';

    /** Closed without merging. */
    public const string CLOSED = 'closed';

    /** GitHub has no such pull request in the support site. */
    public const string NOT_FOUND = 'not found';

    public function __construct(
        public int $number,
        /** One of MERGED, OPEN, CLOSED or NOT_FOUND. */
        public string $state,
    ) {}

    public function reference(): string
    {
        return HelpLine::SUPPORT_SITE.'#'.$this->number;
    }

    public function address(): string
    {
        return 'https://github.com/'.HelpLine::SUPPORT_SITE.'/pull/'.$this->number;
    }

    public function status(): HelpStatus
    {
        return match ($this->state) {
            self::MERGED => HelpStatus::Complete,
            self::OPEN => HelpStatus::Waiting,
            default => HelpStatus::Missing,
        };
    }

    /** "merged", "is still open: merge it before releasing", and so on. */
    public function describe(): string
    {
        return match ($this->state) {
            self::MERGED => 'merged',
            self::OPEN => 'still open: merge it before releasing',
            self::CLOSED => 'closed without merging',
            default => "isn't a pull request GitHub shows in ".HelpLine::SUPPORT_SITE,
        };
    }
}
