<?php

namespace App\Reports\Gaps;

use App\Content\Article;
use App\Content\AudienceTier;
use App\Records\Answer;
use App\Records\MonthlyUptime;
use App\Records\Records;
use App\Records\SearchOutcome;
use App\Reports\ReportMonth;
use Illuminate\Support\Collection;

/**
 * One month's figures for the Help Gaps Report's Measures table, from the records that are kept
 * for good: monthly totals of Searches and votes (Records::monthlyTotals()), Page View Counts,
 * Still Stuck Counts and monthly uptime. So last month and the same month a year earlier are
 * worked out exactly as the month reported on.
 */
final readonly class MonthFigures
{
    public function __construct(
        public int $searches,
        public int $noResults,
        public int $opened,
        public int $answeredAtTop,
        public int $staffVotes,
        public int $staffYes,
        public int $clientVotes,
        public int $clientYes,
        public int $articleViews,
        public int $stillStuck,
        public ?MonthlyUptime $uptime,
    ) {}

    /**
     * @param  Collection<string, Article>  $articles  every Article by its Article Name, for its tier
     */
    public static function of(ReportMonth $month, Records $records, Collection $articles): self
    {
        $totals = $records->monthlyTotals($month->first);
        $tier = fn (string $name): AudienceTier => $articles->get($name)->audience_tier ?? AudienceTier::Staff;

        $votes = ['staff' => 0, 'staffYes' => 0, 'client' => 0, 'clientYes' => 0];
        foreach ($totals->voteTotals ?? [] as $total) {
            $key = $tier($total['article']) === AudienceTier::Client ? 'client' : 'staff';
            $votes[$key] += $total['votes'];
            $votes[$key.'Yes'] += $total['answer'] === Answer::Yes ? $total['votes'] : 0;
        }

        // "Still stuck?" is on Staff Articles only, so only their views can be followed by it.
        $articleViews = 0;
        foreach ($records->pageViews($month->first, $month->end()) as $page => $views) {
            $name = preg_match('#\A/articles/([a-z0-9-]+)\z#', $page, $match) === 1 ? $match[1] : null;
            if ($name !== null && $articles->has($name) && $tier($name) === AudienceTier::Staff) {
                $articleViews += $views;
            }
        }

        return new self(
            searches: $totals?->searches() ?? 0,
            noResults: $totals?->searches(SearchOutcome::NoResults) ?? 0,
            opened: $totals?->searches(SearchOutcome::Opened) ?? 0,
            answeredAtTop: $totals?->answeredAtTop() ?? 0,
            staffVotes: $votes['staff'],
            staffYes: $votes['staffYes'],
            clientVotes: $votes['client'],
            clientYes: $votes['clientYes'],
            articleViews: $articleViews,
            stillStuck: array_sum($records->stillStuckCounts($month->first, $month->end())),
            uptime: $records->monthlyUptime($month->first),
        );
    }

    /** Searches that showed something, a Glossary Entry or results. */
    public function showedResults(): int
    {
        return $this->searches - $this->noResults;
    }
}
