<?php

namespace App\Records;

use Carbon\CarbonImmutable;

/**
 * One month's Search Records and votes added up (Records::monthlyTotals()), kept for good after
 * the records themselves are pruned, so the Help Gaps Report can compare a month with the same
 * month a year earlier. Numbers only: no search words, comments or days.
 */
final readonly class MonthlyTotals
{
    /**
     * @param  CarbonImmutable  $month  The month's first day.
     * @param  list<array{where: string, outcome: SearchOutcome, glossaryEntryShown: bool, searches: int}>  $searchTotals
     * @param  list<array{article: string, where: string, answer: Answer, reason: ?FeedbackReason, votes: int}>  $voteTotals
     */
    public function __construct(
        public CarbonImmutable $month,
        public array $searchTotals,
        public array $voteTotals,
    ) {}

    /** The month's Searches, or those with one outcome. */
    public function searches(?SearchOutcome $outcome = null): int
    {
        return array_sum(array_map(
            fn (array $total): int => $outcome === null || $total['outcome'] === $outcome ? $total['searches'] : 0,
            $this->searchTotals,
        ));
    }

    /**
     * Searches answered at the top: a Glossary Entry was shown and nothing was opened. The
     * "Search finds the right answer" measure leaves them out and shows them on their own line.
     */
    public function answeredAtTop(): int
    {
        return array_sum(array_map(
            fn (array $total): int => $total['glossaryEntryShown'] && $total['outcome'] === SearchOutcome::NotOpened ? $total['searches'] : 0,
            $this->searchTotals,
        ));
    }

    /** The month's votes, on one Article and/or with one answer. */
    public function votes(?string $article = null, ?Answer $answer = null): int
    {
        return array_sum(array_map(
            fn (array $total): int => ($article === null || $total['article'] === $article) && ($answer === null || $total['answer'] === $answer)
                ? $total['votes'] : 0,
            $this->voteTotals,
        ));
    }

    /**
     * How often each Feedback Reason was given, on one Article or all, in the reasons' own order;
     * reasons nobody gave are left out.
     *
     * @return array<string, int> reason => votes
     */
    public function reasons(?string $article = null): array
    {
        $reasons = [];
        foreach (FeedbackReason::cases() as $reason) {
            $votes = array_sum(array_map(
                fn (array $total): int => $total['reason'] === $reason && ($article === null || $total['article'] === $article) ? $total['votes'] : 0,
                $this->voteTotals,
            ));
            if ($votes > 0) {
                $reasons[$reason->value] = $votes;
            }
        }

        return $reasons;
    }

    /**
     * The Articles voted on in the month, by Article Name.
     *
     * @return list<string>
     */
    public function articles(): array
    {
        $articles = array_values(array_unique(array_column($this->voteTotals, 'article')));
        sort($articles);

        return $articles;
    }
}
