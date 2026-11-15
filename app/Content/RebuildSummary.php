<?php

namespace App\Content;

/** What one rebuild put in the database copy. */
final readonly class RebuildSummary
{
    public function __construct(
        public int $published,
        public int $drafts,
        public int $retired,
        /** @var list<BrokenLink> Links that lead nowhere, shown as plain text. */
        public array $brokenLinks = [],
        /** Entries in the staff Glossary and the Client Glossary. */
        public int $glossaryEntries = 0,
        public int $clientGlossaryEntries = 0,
        /** Release Notes published, one per FMM Release. */
        public int $releaseNotes = 0,
        /** Change Summaries waiting in the Next Release folder (checked, never shown). */
        public int $changeSummaries = 0,
        /** Published Articles waiting, hidden, for their feature to reach the demo (ADR-0006). */
        public int $waitingForDemo = 0,
    ) {}

    public function articles(): int
    {
        return $this->published + $this->waitingForDemo + $this->drafts + $this->retired;
    }
}
