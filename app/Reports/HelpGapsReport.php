<?php

namespace App\Reports;

use App\Content\Article;
use App\Content\AudienceTier;
use App\Content\ContentLibrary;
use App\IssueDesk\IssueText;
use App\Records\Answer;
use App\Records\ArticleVote;
use App\Records\FeedbackReason;
use App\Records\MonthlyUptime;
use App\Records\Records;
use App\Records\SearchOutcome;
use App\Records\SearchRecord;
use App\Reports\Gaps\CandidateOtherNames;
use App\Reports\Gaps\GapsReport;
use App\Reports\Gaps\MonthFigures;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The Help Gaps Report (spec #30, user stories 150 to 158; plan "Success measures" and S9): once
 * a month, where readers failed to find help, from the help centre's own records, which only its
 * server holds. It opens with the Measures table of every Reported Measure SR1 can work out
 * beside last month and the same month a year earlier, then S9's gaps list:
 *
 * 1. the commonest No-results and Not-opened Searches, and candidate Other Names;
 * 2. Articles under 80% helpful with at least five votes since their Checked On, with their
 *    Feedback Reasons and comments;
 * 3. "Self-service works", by the Articles readers were most often still stuck on;
 * 4. the Getting-started Path's Page View Counts step by step, until SR2 measures
 *    "Self-sufficient start";
 * 5. monthly uptime.
 *
 * Counting starts when the help centre goes public: nothing is recorded before then, so a month
 * before the first record shows "before going public". A share is judged against its target only
 * in a month that reaches its minimum; below it, it shows "too few to judge" with its counts.
 *
 * help:gaps-report raises it on the first working day of the month, linking that month's Help
 * Drift Report (HelpDriftReport::marker()).
 */
final readonly class HelpGapsReport
{
    /** The label on every Help Gaps Report issue. */
    public const LABEL = 'help-gaps-report';

    /** The two search measures need this many Searches in the month to be judged. */
    public const MINIMUM_SEARCHES = 200;

    /** Each tier's helpful share needs this many votes in the month to be judged. */
    public const MINIMUM_VOTES = 50;

    /** An Article is judged on its own once it has this many votes since its Checked On. */
    public const MINIMUM_ARTICLE_VOTES = 5;

    /** Self-service works needs this many Staff Article views in the month to be judged. */
    public const MINIMUM_ARTICLE_VIEWS = 500;

    /** How many of the commonest Searches each list shows. */
    public const COMMONEST = 20;

    /** How many Articles "Self-service works" lists, and comments each Article under 80% shows. */
    private const MOST_LISTED = 10;

    public function __construct(private Records $records, private ContentLibrary $library) {}

    /** The hidden first line of the month's issue: "<!-- help-gaps-report:2026-11 -->". */
    public static function marker(ReportMonth $month): string
    {
        return '<!-- '.self::LABEL.':'.$month->key().' -->';
    }

    /** "Help gaps: November 2026", as the plan names it. */
    public static function title(ReportMonth $month): string
    {
        return 'Help gaps: '.$month->name();
    }

    /**
     * @param  CarbonImmutable  $day  the report day (UK), on which "Docs stay current" is worked out
     * @param  int|null  $driftReport  the month's Help Drift Report issue, when it is raised
     * @param  bool  $lookedForDriftReport  whether GitHub was asked for it (not when only printing without the key)
     */
    public function make(ReportMonth $month, CarbonImmutable $day, ?int $driftReport, bool $lookedForDriftReport = true): GapsReport
    {
        $countingSince = $this->records->countingSince();
        $articles = $this->library->articlesByName();
        $searches = $this->records->searches($month->first, $month->end());
        $noResults = self::commonest($searches, fn ($search): bool => $search->outcome === SearchOutcome::NoResults);
        $notOpened = self::commonest($searches, fn ($search): bool => $search->outcome === SearchOutcome::NotOpened && ! $search->glossaryEntryShown);

        return new GapsReport($month, $day, [
            self::marker($month),
            "Where readers failed to find help in {$month->name()}, from the help centre's own records, made on {$day->format('j F Y')}. ".match (true) {
                $driftReport !== null => "The Help Drift Report for {$month->name()} is #{$driftReport}.",
                $lookedForDriftReport => "The Help Drift Report for {$month->name()} hadn't been raised when this report was made: find it by its label, `".HelpDriftReport::LABEL.'`.',
                default => "The Help Drift Report for {$month->name()} wasn't looked for.",
            },
            '',
            'This is S9\'s gaps list: answer what readers couldn\'t find, rewrite what didn\'t help, and give search readers\' own words as Other Names. A missed target is reported here and never opens a ticket by itself.',
        ], [
            'Measures' => $this->measures($month, $day, $countingSince, $articles),
            'No-results Searches' => self::searchList($noResults, "The commonest Searches in {$month->name()} that showed nothing, not even a Glossary Entry (at most ".self::COMMONEST.'). Once one has its answer, add it to the Findability Set.'),
            'Not-opened Searches' => self::searchList($notOpened, "The commonest Searches in {$month->name()} that showed results and had none opened (at most ".self::COMMONEST.'), leaving out those answered at the top by a Glossary Entry.'),
            'Candidate Other Names' => $this->candidates($month, $noResults),
            'Articles under 80% helpful' => $this->underEighty($month),
            'Self-service works' => $this->selfService($month, $articles),
            'The Getting-started Path' => $this->gettingStarted($month),
            'Uptime' => self::uptime($month, $this->records->monthlyUptime($month->first)),
        ]);
    }

    /**
     * @param  Collection<string, Article>  $articles
     */
    private function measures(ReportMonth $month, CarbonImmutable $day, ?CarbonImmutable $countingSince, Collection $articles): string
    {
        $months = [$month, $month->previous(), $month->yearEarlier()];
        $figures = array_map(
            fn (ReportMonth $column): ?MonthFigures => $countingSince !== null && $countingSince->lessThanOrEqualTo($column->end())
                ? MonthFigures::of($column, $this->records, $articles) : null,
            $months,
        );
        $published = $this->library->publishedArticles();
        $current = $published->filter(fn (Article $article): bool => ! $article->next_review_on->lessThan($day))->count();

        $rows = [
            ['Self-service works: Staff Article views not followed by "Still stuck?"', 'Over 85%', fn (MonthFigures $f): string => self::share(
                max(0, $f->articleViews - $f->stillStuck), $f->articleViews, $f->articleViews >= self::MINIMUM_ARTICLE_VIEWS, fn (float $share): bool => $share > 0.85,
                "{$f->stillStuck} Still stuck of ".self::count($f->articleViews, 'Article view'),
            )],
            ['Search finds answers: Searches that return no results', 'Under 5%', fn (MonthFigures $f): string => self::share(
                $f->noResults, $f->searches, $f->searches >= self::MINIMUM_SEARCHES, fn (float $share): bool => $share < 0.05,
                "{$f->noResults} No results of ".self::count($f->searches, 'Search'),
            )],
            ['Search finds the right answer: Searches where a result is opened', 'Over 70%', fn (MonthFigures $f): string => self::share(
                $f->opened, $f->showedResults() - $f->answeredAtTop, $f->searches >= self::MINIMUM_SEARCHES, fn (float $share): bool => $share > 0.7,
                "{$f->opened} Opened of ".self::count($f->showedResults() - $f->answeredAtTop, 'Search').' that showed results ('.self::count($f->searches, 'Search').')',
            )],
            ['Answered at the top: Searches whose top answer was a Glossary Entry, not opened', 'Shown on its own', fn (MonthFigures $f): string => self::count($f->answeredAtTop, 'Search')],
            ['Articles help: Staff votes marked helpful', 'Over 80%', fn (MonthFigures $f): string => self::share(
                $f->staffYes, $f->staffVotes, $f->staffVotes >= self::MINIMUM_VOTES, fn (float $share): bool => $share > 0.8,
                "{$f->staffYes} Yes of ".self::count($f->staffVotes, 'vote'),
            )],
            ['Articles help: Client-tier votes marked helpful', 'Over 80%', fn (MonthFigures $f): string => self::share(
                $f->clientYes, $f->clientVotes, $f->clientVotes >= self::MINIMUM_VOTES, fn (float $share): bool => $share > 0.8,
                "{$f->clientYes} Yes of ".self::count($f->clientVotes, 'vote'),
            )],
            ['Docs stay current: Published Articles within their review date, on the report day', 'Over 95%', fn (MonthFigures $f, int $column): string => match (true) {
                $column > 0 => '—',
                $published->isEmpty() => 'no Published Articles',
                default => self::share($current, $published->count(), true, fn (float $share): bool => $share > 0.95, "{$current} of ".self::count($published->count(), 'Published Article')),
            }],
            ['Help centre stays up: Monthly uptime', 'None before SR2: reported only', fn (MonthFigures $f): string => match (true) {
                $f->uptime === null => 'missing',
                $f->uptime->isComplete() => "**{$f->uptime->percent()}%**",
                default => "**{$f->uptime->percent()}%** to {$f->uptime->through->format('j F')} only",
            }],
        ];

        $lines = [
            '| Reported Measure | Target | '.implode(' | ', array_map(fn (ReportMonth $column): string => $column->name(), $months)).' |',
            '| --- | --- | --- | --- | --- |',
        ];
        foreach ($rows as [$measure, $target, $cell]) {
            $cells = [];
            foreach ($figures as $column => $figure) {
                $cells[] = $figure === null ? 'before going public' : $cell($figure, $column);
            }
            $lines[] = "| {$measure} | {$target} | ".implode(' | ', $cells).' |';
        }

        return implode("\n", [
            ...$lines,
            '',
            '- A share is judged against its target only in a month that reaches its minimum: '.self::MINIMUM_SEARCHES.' Searches for the two search measures, '
                .self::MINIMUM_VOTES.' votes for each tier\'s helpful share and '.self::MINIMUM_ARTICLE_VIEWS.' Article views for Self-service works. Below it a share shows "too few to judge" with its counts.',
            ...$this->countingNotes($month, $countingSince),
            '- Self-service works counts views of Staff Articles only, since only they offer "Still stuck?". Docs stay current is worked out on the report day only, so earlier months show "—".',
            '- Docs keep pace and Glossary keeps up come from the repositories, not these records: see the Release Checks and the Help Drift Report\'s Glossary Check section. Self-sufficient start waits for SR2; until then the Getting-started Path below shows where readers drop off.',
        ]);
    }

    /** @return list<string> */
    private function countingNotes(ReportMonth $month, ?CarbonImmutable $countingSince): array
    {
        if ($countingSince === null) {
            return [];
        }
        $baselineStart = ReportMonth::of(($countingSince->day === 1 ? $countingSince : $countingSince->startOfMonth()->addMonthNoOverflow())->format('Y-m'));
        $baselineEnd = ReportMonth::of($baselineStart->first->addMonthsNoOverflow(2)->format('Y-m'));
        $place = (int) $baselineStart->first->diffInMonths($month->first, false) + 1;

        return [
            "- Counting started on {$countingSince->format('j F Y')}, when the help centre went public: nothing from before then is counted.",
            ...match (true) {
                $place < 1 => ["- {$month->name()} is the part month before the three-month baseline, which runs from {$baselineStart->name()} to {$baselineEnd->name()}."],
                $place < 3 => ["- {$month->name()} is month {$place} of the three-month baseline ({$baselineStart->name()} to {$baselineEnd->name()}); AJ reviews the targets after it."],
                $place === 3 => ["- {$month->name()} ends the three-month baseline ({$baselineStart->name()} to {$baselineEnd->name()}): review the targets now (keep, raise or lower)."],
                default => [],
            },
        ];
    }

    /**
     * A share as the Measures table shows it: judged against its target once its month reaches
     * its minimum, otherwise "too few to judge"; its counts either way.
     *
     * @param  callable(float): bool  $meets
     */
    private static function share(int $part, int $whole, bool $enough, callable $meets, string $counts): string
    {
        if (! $enough || $whole === 0) {
            return "too few to judge<br>{$counts}";
        }
        $share = $part / $whole;

        return '**'.self::percent($share).'**, '.($meets($share) ? 'met' : 'missed')."<br>{$counts}";
    }

    /**
     * Each Search's words with how many times they were searched, commonest first, then A to Z.
     *
     * @param  list<SearchRecord>  $searches
     * @param  callable(SearchRecord): bool  $counts
     * @return array<string, int>
     */
    private static function commonest(array $searches, callable $counts): array
    {
        $words = [];
        foreach ($searches as $search) {
            if ($counts($search)) {
                $words[$search->words] = ($words[$search->words] ?? 0) + 1;
            }
        }
        uksort($words, fn (int|string $a, int|string $b): int => [$words[$b], (string) $a] <=> [$words[$a], (string) $b]);

        return $words;
    }

    /** @param  array<string, int>  $searches */
    private static function searchList(array $searches, string $about): string
    {
        if ($searches === []) {
            return 'None this month.';
        }
        $lines = [$about, ''];
        $place = 0;
        foreach (array_slice($searches, 0, self::COMMONEST, true) as $words => $times) {
            $lines[] = ++$place.'. '.IssueText::code((string) $words).': '.self::count($times, 'Search');
        }

        return implode("\n", $lines);
    }

    /** @param  array<string, int>  $noResults */
    private function candidates(ReportMonth $month, array $noResults): string
    {
        $candidates = array_slice(CandidateOtherNames::among($noResults, $this->library->glossaryEntries()), 0, self::COMMONEST);
        if ($candidates === []) {
            return 'None this month: no No-results Search is spelt like a term or Other Name.';
        }

        $lines = [
            "No-results Searches in {$month->name()} spelt like a Glossary Entry's term, Other Name or former name, which search's typo rules didn't match. Where readers mean that term, add their word to the entry's `other_names`.",
            '',
        ];
        foreach ($candidates as ['words' => $words, 'searches' => $searches, 'entry' => $entry, 'name' => $name]) {
            $term = "**{$entry->term}**".($entry->audience_tier === AudienceTier::Client ? ' in the Client Glossary' : '');
            $lines[] = '- '.IssueText::code($words).' ('.self::count($searches, 'Search').') looks like '.match (true) {
                $name === $entry->term => $term,
                in_array($name, $entry->other_names, true) => IssueText::code($name).", an Other Name of {$term}",
                default => IssueText::code($name).", a former name of {$term}",
            };
        }

        return implode("\n", $lines);
    }

    private function underEighty(ReportMonth $month): string
    {
        $published = $this->library->publishedArticles();
        if ($published->isEmpty()) {
            return 'None this month: there are no Published Articles.';
        }
        $since = $published->min(fn (Article $article): string => $article->checked_on->toDateString());
        $votes = collect($this->records->votes(CarbonImmutable::parse((string) $since), $month->end()))->groupBy('article');

        $under = [];
        foreach ($published as $article) {
            // Only votes given since its Checked On count: each vote keeps the Checked On of its day.
            $counted = $votes->get($article->name, collect())
                ->filter(fn (ArticleVote $vote): bool => $vote->checkedOn->toDateString() >= $article->checked_on->toDateString())
                ->values();
            $yes = $counted->filter(fn (ArticleVote $vote): bool => $vote->answer === Answer::Yes)->count();
            if ($counted->count() >= self::MINIMUM_ARTICLE_VOTES && $yes / $counted->count() < 0.8) {
                $under[] = ['article' => $article, 'votes' => $counted, 'yes' => $yes];
            }
        }
        if ($under === []) {
            return 'None this month: no Article with at least '.self::MINIMUM_ARTICLE_VOTES.' votes since its Checked On is under 80% helpful.';
        }
        usort($under, fn (array $a, array $b): int => [$a['yes'] / $a['votes']->count(), -$a['votes']->count(), $a['article']->title]
            <=> [$b['yes'] / $b['votes']->count(), -$b['votes']->count(), $b['article']->title]);

        $lines = [
            'Articles with at least '.self::MINIMUM_ARTICLE_VOTES.' votes since their Checked On and fewer than 80% of them Yes, least helpful first, with the Feedback Reasons and comments given since then. Readers\' comments are quoted as they wrote them.',
        ];
        foreach ($under as ['article' => $article, 'votes' => $counted, 'yes' => $yes]) {
            $lines[] = '';
            $lines[] = "### [{$article->title}](".self::address($article).')';
            $lines[] = '';
            $lines[] = '`content/'.$article->source_path.'`, '.($article->audience_tier === AudienceTier::Client ? 'Client tier' : 'Staff').', owner '.IssueText::code($article->owner)
                .": {$yes} Yes of ".self::count($counted->count(), 'vote').' ('.self::percent($yes / $counted->count()).') since its Checked On, '.$article->checked_on->format('j F Y').'.';

            $reasons = [];
            foreach (FeedbackReason::cases() as $reason) {
                $given = $counted->filter(fn (ArticleVote $vote): bool => $vote->reason === $reason)->count();
                if ($given > 0) {
                    $reasons[] = "- {$reason->value}: {$given}";
                }
            }
            $unexplained = $counted->filter(fn (ArticleVote $vote): bool => $vote->answer === Answer::No && $vote->reason === null)->count();
            if ($unexplained > 0) {
                $reasons[] = "- No reason given: {$unexplained}";
            }
            if ($reasons !== []) {
                $lines[] = '';
                $lines[] = 'Feedback Reasons:';
                $lines[] = '';
                array_push($lines, ...$reasons);
            }

            $comments = $counted->filter(fn (ArticleVote $vote): bool => $vote->comment !== null)->reverse()->values();
            if ($comments->isNotEmpty()) {
                $lines[] = '';
                $lines[] = 'Comments, newest first:';
                foreach ($comments->take(self::MOST_LISTED) as $vote) {
                    $lines[] = '';
                    $lines[] = "On {$vote->day->format('j F Y')}:";
                    $lines[] = '';
                    $lines[] = IssueText::quoted((string) $vote->comment);
                }
                if ($comments->count() > self::MOST_LISTED) {
                    $lines[] = '';
                    $lines[] = 'And '.self::count($comments->count() - self::MOST_LISTED, 'older comment').": `php artisan help:delete-comment {$article->name}` lists them all.";
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<string, Article>  $articles
     */
    private function selfService(ReportMonth $month, Collection $articles): string
    {
        $views = $this->records->pageViews($month->first, $month->end());
        $stuck = $this->records->stillStuckCounts($month->first, $month->end());
        $figures = MonthFigures::of($month, $this->records, $articles);

        $lines = [
            '1 − (Still Stuck Counts ÷ Page View Counts of Staff Articles) in '.$month->name().': '
                .self::count($figures->stillStuck, '"Still stuck?" click').' from '.self::count($figures->articleViews, 'view').' of Staff Articles'
                .($figures->articleViews === 0 ? '.' : ', so '.self::percent(max(0, $figures->articleViews - $figures->stillStuck) / $figures->articleViews).' of views needed no email.')
                .' Client-tier Articles have no "Still stuck?" and are left out.',
        ];
        if ($stuck !== []) {
            array_push($lines, '', 'Where readers were most often still stuck:', '', '| Article | Still stuck | Views | Self-service |', '| --- | --- | --- | --- |');
            foreach (array_slice($stuck, 0, self::MOST_LISTED, true) as $name => $clicks) {
                $article = $articles->get((string) $name);
                $viewed = $views['/articles/'.$name] ?? 0;
                $lines[] = '| '.($article === null ? IssueText::code((string) $name) : "[{$article->title}](".self::address($article).')')
                    ." | {$clicks} | {$viewed} | ".($viewed === 0 ? '—' : self::percent(max(0, $viewed - $clicks) / $viewed)).' |';
            }
        }

        return implode("\n", $lines);
    }

    private function gettingStarted(ReportMonth $month): string
    {
        $path = $this->library->gettingStartedPath();
        if ($path->isEmpty()) {
            return 'The Getting-started Path has no Published Articles yet.';
        }
        $views = $this->records->pageViews($month->first, $month->end());
        $first = $views[$path->first()->address()] ?? 0;

        $lines = [
            'Page View Counts of its '.self::count($path->count(), 'step')." in {$month->name()}, to show where new Operators drop off until SR2 measures \"Self-sufficient start\".",
            '',
            '| Step | Article | Views | Of step 1 |',
            '| --- | --- | --- | --- |',
        ];
        foreach ($path->values() as $index => $article) {
            $viewed = $views[$article->address()] ?? 0;
            $lines[] = '| '.($index + 1)." | [{$article->title}](".self::address($article).") | {$viewed} | ".($first === 0 ? '—' : self::percent($viewed / $first)).' |';
        }

        return implode("\n", $lines);
    }

    private static function uptime(ReportMonth $month, ?MonthlyUptime $uptime): string
    {
        return match (true) {
            $uptime === null => "{$month->name()}'s uptime is missing: help:record-uptime never kept a figure for it. Check the server's HELP_UPTIME_* settings and the log of its nightly run.",
            ! $uptime->isComplete() => "{$month->name()}'s uptime is incomplete: it counts only to {$uptime->through->format('j F Y')} ({$uptime->percent()}% to then). help:record-uptime reads the whole month the night after it ends, so check the log of its nightly run.",
            default => "The help centre was up {$uptime->percent()}% of {$month->name()}, from Better Stack's checks of the home page and one Article every five minutes. There is no target before SR2.",
        };
    }

    /** An Article's address in full, as readers and search engines find it (config help.address), wherever the report is made. */
    private static function address(Article $article): string
    {
        return rtrim(config()->string('help.address'), '/').$article->address();
    }

    /** "75.0%" */
    private static function percent(float $share): string
    {
        return number_format($share * 100, 1).'%';
    }

    /** "1 vote", "12 votes" */
    private static function count(int $number, string $what): string
    {
        return $number.' '.str($what)->plural($number);
    }
}
