<?php

use App\Records\Answer;
use App\Records\FeedbackReason;
use App\Records\Records;
use App\Reports\HelpDriftReport;
use App\Reports\ReportMonth;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Support\FakeGitHubIssues;

/*
 * The Help Gaps Report (spec #30, user stories 150 to 158; Further Notes 3; ticket #15's
 * measures): on the first working day of each month the help centre's server raises one issue
 * for the Content Owner from its own records, linking the month's Help Drift Report. It opens with
 * the Measures table beside last month and the same month a year earlier, then S9's gaps list.
 * Records are made as readers make them, through Records, with the clock moved to each day;
 * GitHub is faked at the issue desk (seam 4).
 */

const GAPS_PATH = [
    'get-started' => 40, 'get-started-team' => 30, 'get-started-warehouse' => 25, 'get-started-labels' => 20,
    'get-started-containers' => 18, 'get-started-first-client' => 15, 'get-started-products' => 12,
    'get-started-go-live' => 10, 'get-started-expect-a-delivery' => 8, 'get-started-receive' => 6,
    'get-started-client-sees-stock' => 5,
];

/** Moves the clock to a time on a UK day: midday unless said. */
function gapsOn(string $day, string $time = '12:00'): void
{
    test()->travelTo(CarbonImmutable::parse("{$day} {$time}", Records::TIMEZONE));
}

/** Readers view a page this many times today. */
function gapsViews(string $page, int $views): void
{
    for ($view = 0; $view < $views; $view++) {
        app(Records::class)->countPageView($page, A_READER);
    }
}

/** Readers press "Still stuck?" on a staff Article this many times today. */
function gapsStillStuck(string $article, int $clicks): void
{
    for ($click = 0; $click < $clicks; $click++) {
        app(Records::class)->countStillStuck($article, A_READER);
    }
}

/**
 * Readers settle the same Search this many times today, each ending one way: 'No results',
 * 'Not opened', 'Opened', or 'answered at the top' (a Glossary Entry shown and nothing opened).
 */
function gapsSearches(string $words, int $times, string $ending): void
{
    $records = app(Records::class);
    for ($search = 0; $search < $times; $search++) {
        $id = (string) Str::uuid();
        $records->recordSearch($id, $words, $ending === 'No results' ? 0 : 3, $ending === 'answered at the top', 'help centre', A_READER);
        if ($ending === 'Opened') {
            $records->searchOpened($id, 1, '/articles/receive-a-delivery', A_READER);
        }
    }
}

/** Readers vote on an Article today this many times, when its Checked On was $checkedOn. */
function gapsVotes(string $article, string $checkedOn, Answer $answer, int $times = 1, ?FeedbackReason $reason = null, ?string $comment = null): void
{
    $records = app(Records::class);
    for ($vote = 0; $vote < $times; $vote++) {
        $reference = $records->recordVote($article, CarbonImmutable::parse($checkedOn), $answer, 'help centre', A_READER);
        $records->giveReason($article, (string) $reference, $reason, $comment);
    }
}

/** A page's address in full, as the report links it: the help centre's real address. */
function gapsAddress(string $path): string
{
    return 'https://help.firstmilemanager.co.uk'.$path;
}

/** @return array{0: int, 1: string} the exit code and output */
function gapsReport(array $options = []): array
{
    $exitCode = Artisan::call('help:gaps-report', $options);

    return [$exitCode, Artisan::output()];
}

/** The Help Drift Report's workflow raises a month's issue, as it does on GitHub. */
function gapsDriftReportRaised(FakeGitHubIssues $github, string $month): void
{
    $month = ReportMonth::of($month);
    $github->raised(HelpDriftReport::title($month), HelpDriftReport::marker($month)."\nWhere help may have fallen behind Stockist.\n", [HelpDriftReport::LABEL]);
}

/**
 * The Help Gaps Report issues raised, each as the Content Owner reads it.
 *
 * @return list<array{number: int, title: string, body: string, assignees: list<string>, labels: list<string>, state: string, comments: list<string>}>
 */
function gapsIssues(FakeGitHubIssues $github): array
{
    return array_values(array_filter($github->issues(), fn (array $issue): bool => in_array('help-gaps-report', $issue['labels'], true)));
}

/** One section of a report's body, from its "## " heading to the next, without the heading. */
function gapsSection(string $body, string $heading): string
{
    expect($body)->toContain("\n## {$heading}\n");
    $after = Str::after($body, "\n## {$heading}\n");

    return trim(Str::before($after, "\n## "));
}

/** What the server's scheduler runs to raise the Help Gaps Report. */
function gapsReportRun(): Event
{
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        fn (Event $event): bool => str_contains((string) $event->command, 'help:gaps-report'),
    ));
    expect($events)->toHaveCount(1);

    return $events[0];
}

beforeEach(function () {
    config(['help.going_public' => true]);
    gapsOn('2026-10-30');
    $this->github = FakeGitHubIssues::start();

    $content = content()
        ->article('receive-a-delivery', ['title' => 'Receive a delivery', 'owner' => 'sam-trentside', 'checked_on' => '2026-10-24'])
        ->article('count-stock', ['title' => 'Count stock', 'checked_on' => '2026-11-10'])
        ->article('put-away-stock', ['title' => 'Put away stock', 'checked_on' => '2026-10-24'])
        ->article('an-old-reference', ['type' => 'Reference', 'checked_on' => '2026-05-30', 'next_review_on' => '2026-11-30'])
        ->article('follow-your-deliveries', ['title' => 'Follow your deliveries', 'audience_tier' => 'Client', 'checked_on' => '2026-10-24'])
        ->glossaryEntry('location', ['other_names' => ['bin']], "A **Location** is one place in the warehouse where stock is kept.\n");
    $step = 0;
    foreach (array_keys(GAPS_PATH) as $name) {
        $content->article($name, ['type' => 'Getting-started', 'path_position' => ++$step, 'title' => 'Step '.$step.': '.$name]);
    }
    $content->publish();
});

/** A reader views the help home in November 2026, so the month has something counted. */
function gapsSomethingCounted(): void
{
    gapsOn('2026-11-04');
    gapsViews('/', 1);
}

/** A year of records: the help centre went public on 10 November 2025. */
function gapsAYearOfRecords(): void
{
    $records = app(Records::class);

    gapsOn('2025-11-10');
    gapsViews('/articles/receive-a-delivery', 50);
    $records->recordUptime(CarbonImmutable::parse('2025-11-01'), 99.5, CarbonImmutable::parse('2025-11-30'));

    gapsOn('2026-10-25');
    gapsViews('/articles/receive-a-delivery', 400);
    gapsStillStuck('receive-a-delivery', 10);
    gapsSearches('receive a delivery', 150, 'Opened');
    gapsVotes('receive-a-delivery', '2026-10-24', Answer::No, reason: FeedbackReason::HardToFollow, comment: 'Which dock door do I use?');
    $records->recordUptime(CarbonImmutable::parse('2026-10-01'), 99.8, CarbonImmutable::parse('2026-10-31'));

    gapsOn('2026-11-04');
    gapsViews('/', 1000);
    gapsViews('/articles/receive-a-delivery', 300);
    gapsViews('/articles/put-away-stock', 250);
    gapsViews('/articles/count-stock', 50);
    gapsViews('/articles/follow-your-deliveries', 100);
    foreach (GAPS_PATH as $name => $views) {
        gapsViews("/articles/{$name}", $views);
    }
    gapsStillStuck('receive-a-delivery', 30);
    gapsStillStuck('count-stock', 6);

    gapsSearches('returns label', 9, 'No results');
    gapsSearches('lokashun', 6, 'No results');
    gapsSearches('bim', 3, 'No results');
    gapsSearches('cancel a client', 2, 'No results');
    gapsSearches('stock take', 8, 'Not opened');
    gapsSearches('cycle count', 4, 'Not opened');
    gapsSearches('location', 10, 'answered at the top');
    gapsSearches('put away', 198, 'Opened');

    // Count stock was checked again on 10 November: the five votes before then no longer count.
    gapsVotes('count-stock', '2026-10-24', Answer::No, 5, FeedbackReason::DidntAnswer, 'Out of date.');
    gapsVotes('put-away-stock', '2026-10-24', Answer::Yes, 45);
    gapsVotes('put-away-stock', '2026-10-24', Answer::No);
    gapsVotes('follow-your-deliveries', '2026-10-24', Answer::Yes, 4);
    gapsVotes('follow-your-deliveries', '2026-10-24', Answer::No);

    gapsOn('2026-11-20');
    gapsVotes('receive-a-delivery', '2026-10-24', Answer::Yes, 2);
    gapsVotes('receive-a-delivery', '2026-10-24', Answer::No, 2, FeedbackReason::HardToFollow);
    gapsVotes('receive-a-delivery', '2026-10-24', Answer::No, reason: FeedbackReason::DidntAnswer, comment: 'Says "Receive" but the button is "Book in".');
    gapsVotes('count-stock', '2026-11-10', Answer::Yes);
    gapsVotes('count-stock', '2026-11-10', Answer::No, 3, FeedbackReason::HardToFollow, 'Too long.');

    $records->recordUptime(CarbonImmutable::parse('2026-11-01'), 99.95, CarbonImmutable::parse('2026-11-30'));
}

it('raises the month\'s issue for the Content Owner on the first working day, with every section', function () {
    gapsAYearOfRecords();
    gapsOn('2026-12-01', '08:00');
    gapsDriftReportRaised($this->github, '2026-11');

    [$exitCode, $output] = gapsReport();

    $issues = gapsIssues($this->github);
    expect($exitCode)->toBe(Command::SUCCESS, $output)
        ->and($output)->toContain('Raised the Help Gaps Report for November 2026: https://github.com/gpl-ajackson/firstmilemanager-help/issues/2')
        ->and($issues)->toHaveCount(1)
        ->and($issues[0]['title'])->toBe('Help gaps: November 2026')
        ->and($issues[0]['assignees'])->toBe(['gpl-ajackson'])
        ->and($issues[0]['labels'])->toBe(['help-gaps-report']);
    $body = $issues[0]['body'];
    expect($body)->toStartWith("<!-- help-gaps-report:2026-11 -->\n")
        ->toContain('The Help Drift Report for November 2026 is #1.');

    expect(gapsSection($body, 'Measures'))->toContain(implode("\n", [
        '| Reported Measure | Target | November 2026 | October 2026 | November 2025 |',
        '| --- | --- | --- | --- | --- |',
        '| Self-service works: Staff Article views not followed by "Still stuck?" | Over 85% | **95.4%**, met<br>36 Still stuck of 789 Article views | too few to judge<br>10 Still stuck of 400 Article views | too few to judge<br>0 Still stuck of 50 Article views |',
        '| Search finds answers: Searches that return no results | Under 5% | **8.3%**, missed<br>20 No results of 240 Searches | too few to judge<br>0 No results of 150 Searches | too few to judge<br>0 No results of 0 Searches |',
        '| Search finds the right answer: Searches where a result is opened | Over 70% | **94.3%**, met<br>198 Opened of 210 Searches that showed results (240 Searches) | too few to judge<br>150 Opened of 150 Searches that showed results (150 Searches) | too few to judge<br>0 Opened of 0 Searches that showed results (0 Searches) |',
        '| Answered at the top: Searches whose top answer was a Glossary Entry, not opened | Shown on its own | 10 Searches | 0 Searches | 0 Searches |',
        '| Articles help: Staff votes marked helpful | Over 80% | **80.0%**, missed<br>48 Yes of 60 votes | too few to judge<br>0 Yes of 1 vote | too few to judge<br>0 Yes of 0 votes |',
        '| Articles help: Client-tier votes marked helpful | Over 80% | too few to judge<br>4 Yes of 5 votes | too few to judge<br>0 Yes of 0 votes | too few to judge<br>0 Yes of 0 votes |',
        '| Docs stay current: Published Articles within their review date, on the report day | Over 95% | **93.8%**, missed<br>15 of 16 Published Articles | — | — |',
        '| Help centre stays up: Monthly uptime | None before SR2: reported only | **99.95%** | **99.8%** | **99.5%** |',
    ]))
        ->toContain('Counting started on 10 November 2025, when the help centre went public: nothing from before then is counted.')
        ->toContain('Docs keep pace and Glossary keeps up');

    expect(gapsSection($body, 'No-results Searches'))->toContain(implode("\n", [
        '1. `returns label`: 9 Searches',
        '2. `lokashun`: 6 Searches',
        '3. `bim`: 3 Searches',
        '4. `cancel a client`: 2 Searches',
    ]));
    expect(gapsSection($body, 'Not-opened Searches'))->toContain("1. `stock take`: 8 Searches\n2. `cycle count`: 4 Searches")
        ->not->toContain('`location`');
    expect(gapsSection($body, 'Candidate Other Names'))->toContain(implode("\n", [
        '- `lokashun` (6 Searches) looks like **Location**',
        '- `bim` (3 Searches) looks like `bin`, an Other Name of **Location**',
    ]))->not->toContain('returns label');

    $underEighty = gapsSection($body, 'Articles under 80% helpful');
    expect($underEighty)->toContain('### [Receive a delivery]('.gapsAddress('/articles/receive-a-delivery').')')
        ->toContain('`content/articles/receive-a-delivery.md`, Staff, owner `sam-trentside`: 2 Yes of 6 votes (33.3%) since its Checked On, 24 October 2026.')
        ->toContain("- It didn't answer my question: 1\n- It was hard to follow: 3\n\n")
        ->not->toContain('No reason given')
        ->toContain("On 20 November 2026:\n\n```text\nSays \"Receive\" but the button is \"Book in\".\n```")
        ->toContain("On 25 October 2026:\n\n```text\nWhich dock door do I use?\n```")
        ->not->toContain('Count stock')
        ->not->toContain('Put away stock');

    expect(gapsSection($body, 'Self-service works'))->toContain('36 "Still stuck?" clicks from 789 views of Staff Articles')
        ->toContain('| [Receive a delivery]('.gapsAddress('/articles/receive-a-delivery').') | 30 | 300 | 90.0% |')
        ->toContain('| [Count stock]('.gapsAddress('/articles/count-stock').') | 6 | 50 | 88.0% |');

    $path = gapsSection($body, 'The Getting-started Path');
    expect($path)->toContain('| 1 | [Step 1: get-started]('.gapsAddress('/articles/get-started').') | 40 | 100.0% |')
        ->toContain('| 2 | [Step 2: get-started-team]('.gapsAddress('/articles/get-started-team').') | 30 | 75.0% |')
        ->toContain('| 11 | [Step 11: get-started-client-sees-stock]('.gapsAddress('/articles/get-started-client-sees-stock').') | 5 | 12.5% |')
        ->and(substr_count($path, '| [Step '))->toBe(11);

    expect(gapsSection($body, 'Uptime'))->toContain('The help centre was up 99.95% of November 2026');
});

it('shows a share below its minimum as "too few to judge", with its counts', function () {
    gapsOn('2026-11-04');
    gapsViews('/articles/receive-a-delivery', 499);
    gapsStillStuck('receive-a-delivery', 4);
    gapsSearches('put away', 198, 'Opened');
    gapsSearches('returns label', 1, 'No results');
    gapsVotes('put-away-stock', '2026-10-24', Answer::Yes, 49);
    gapsOn('2026-12-01', '16:00');

    gapsReport();

    $measures = gapsSection(gapsIssues($this->github)[0]['body'], 'Measures');
    expect($measures)->toContain('| Over 85% | too few to judge<br>4 Still stuck of 499 Article views |')
        ->toContain('| Under 5% | too few to judge<br>1 No results of 199 Searches |')
        ->toContain('| Over 70% | too few to judge<br>198 Opened of 198 Searches that showed results (199 Searches) |')
        ->toContain('| Over 80% | too few to judge<br>49 Yes of 49 votes |')
        ->toContain('Below it a share shows "too few to judge" with its counts.');
});

it('judges a share once its month reaches the minimum', function () {
    gapsOn('2026-11-04');
    gapsViews('/articles/receive-a-delivery', 500);
    gapsSearches('put away', 199, 'Opened');
    gapsSearches('returns label', 1, 'No results');
    gapsVotes('put-away-stock', '2026-10-24', Answer::Yes, 50);
    gapsOn('2026-12-01', '16:00');

    gapsReport();

    $measures = gapsSection(gapsIssues($this->github)[0]['body'], 'Measures');
    expect($measures)->toContain('| Over 85% | **100.0%**, met<br>0 Still stuck of 500 Article views |')
        ->toContain('| Under 5% | **0.5%**, met<br>1 No results of 200 Searches |')
        ->toContain('| Over 80% | **100.0%**, met<br>50 Yes of 50 votes |');
});

it('never lists an Article with four votes since its Checked On, and leaves out votes from before it', function () {
    gapsOn('2026-11-04');
    // Before Count stock's Checked On (10 November): five Nos that no longer count.
    gapsVotes('count-stock', '2026-10-24', Answer::No, 5, FeedbackReason::DidntAnswer, 'Out of date.');
    gapsOn('2026-11-20');
    gapsVotes('count-stock', '2026-11-10', Answer::No, 4, FeedbackReason::HardToFollow);
    gapsVotes('receive-a-delivery', '2026-10-24', Answer::Yes);
    gapsVotes('receive-a-delivery', '2026-10-24', Answer::No, 4, FeedbackReason::HardToFollow, 'Too long.');
    gapsVotes('put-away-stock', '2026-10-24', Answer::Yes, 4);
    gapsVotes('put-away-stock', '2026-10-24', Answer::No);
    gapsOn('2026-12-01', '16:00');

    gapsReport();

    $underEighty = gapsSection(gapsIssues($this->github)[0]['body'], 'Articles under 80% helpful');
    expect($underEighty)->toContain('### [Receive a delivery]')
        ->toContain('1 Yes of 5 votes (20.0%) since its Checked On, 24 October 2026.')
        ->not->toContain('Count stock')
        ->not->toContain('Out of date.')
        ->not->toContain('Put away stock');

    // The same report, printed on the server rather than raised.
    [, $printed] = gapsReport(['--print' => true]);
    expect(gapsSection($printed, 'Articles under 80% helpful'))->toBe($underEighty);
});

it('says so when no Article is under 80% helpful', function () {
    gapsOn('2026-11-20');
    gapsVotes('count-stock', '2026-11-10', Answer::No, 4, FeedbackReason::HardToFollow);
    gapsOn('2026-12-01', '16:00');

    gapsReport();

    expect(gapsSection(gapsIssues($this->github)[0]['body'], 'Articles under 80% helpful'))
        ->toBe('None this month: no Article with at least 5 votes since its Checked On is under 80% helpful.');
});

it('keeps a reader\'s comment as their words, never as GitHub markup', function () {
    gapsOn('2026-11-20');
    $comment = "@gpl-ajackson see #12 and **this**\n```\n<!-- help-gaps-report:2026-11 -->";
    gapsVotes('receive-a-delivery', '2026-10-24', Answer::No, 5, FeedbackReason::SomethingElse, $comment);
    gapsOn('2026-12-01', '16:00');

    gapsReport();

    expect(gapsIssues($this->github)[0]['body'])->toContain("````text\n{$comment}\n````");
});

it('links the month\'s Help Drift Report, waiting for it until the last run of the day', function () {
    gapsSomethingCounted();
    gapsOn('2026-12-01', '08:00');

    [$exitCode, $output] = gapsReport(['--wait-until' => '16:00']);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($output)->toContain('The Help Drift Report for November 2026 isn\'t raised yet, so the Help Gaps Report waits for a later run (until 16:00).')
        ->and($this->github->issues())->toBe([]);

    gapsOn('2026-12-01', '08:40');
    gapsDriftReportRaised($this->github, '2026-11');
    gapsOn('2026-12-01', '09:00');
    gapsReport(['--wait-until' => '16:00']);

    expect(gapsIssues($this->github))->toHaveCount(1)
        ->and(gapsIssues($this->github)[0]['body'])->toContain('The Help Drift Report for November 2026 is #1.');
});

it('raises it without the Help Drift Report at the last run, saying so', function () {
    gapsSomethingCounted();
    gapsOn('2026-12-01', '16:00');
    gapsDriftReportRaised($this->github, '2026-10');

    [$exitCode] = gapsReport(['--wait-until' => '16:00']);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and(gapsIssues($this->github))->toHaveCount(1)
        ->and(gapsIssues($this->github)[0]['body'])
        ->toContain('The Help Drift Report for November 2026 hadn\'t been raised when this report was made: find it by its label, `help-drift-report`.')
        ->not->toContain('#1');
});

it('raises exactly one issue a month, however often it runs', function () {
    gapsSomethingCounted();
    gapsDriftReportRaised($this->github, '2026-11');
    foreach (['08:00', '09:00', '16:00'] as $time) {
        gapsOn('2026-12-01', $time);
        [$exitCode, $output] = gapsReport(['--wait-until' => '16:00']);
        expect($exitCode)->toBe(Command::SUCCESS);
    }
    $this->github->close(2);
    [, $output] = gapsReport();

    expect($output)->toContain('The Help Gaps Report for November 2026 is already raised: #2.')
        ->and(gapsIssues($this->github))->toHaveCount(1);

    gapsOn('2026-12-10');
    gapsViews('/', 1);
    gapsOn('2027-01-04', '09:00');
    gapsDriftReportRaised($this->github, '2026-12');
    gapsReport();

    expect(array_column(gapsIssues($this->github), 'title'))->toBe(['Help gaps: November 2026', 'Help gaps: December 2026']);
});

it('runs hourly on the first working day only, after the night\'s uptime run', function () {
    $due = function (string $moment): bool {
        test()->travelTo(CarbonImmutable::parse($moment, Records::TIMEZONE));

        return gapsReportRun()->isDue(app()) && gapsReportRun()->filtersPass(app());
    };

    expect(gapsReportRun()->command)->toContain('help:gaps-report --wait-until=16:00')
        ->and($due('2026-12-01 04:30'))->toBeFalse()
        ->and($due('2026-12-01 07:00'))->toBeFalse()
        ->and($due('2026-12-01 08:00'))->toBeTrue()
        ->and($due('2026-12-01 12:00'))->toBeTrue()
        ->and($due('2026-12-01 16:00'))->toBeTrue()
        ->and($due('2026-12-01 17:00'))->toBeFalse()
        ->and($due('2026-12-02 09:00'))->toBeFalse()
        // 1 November 2026 is a Sunday, so the reports go out on Monday the 2nd.
        ->and($due('2026-11-01 09:00'))->toBeFalse()
        ->and($due('2026-11-02 09:00'))->toBeTrue();
});

it('says plainly when the month\'s uptime is missing or incomplete', function (?string $through, string $says) {
    gapsOn('2026-11-04');
    gapsViews('/articles/receive-a-delivery', 10);
    if ($through !== null) {
        app(Records::class)->recordUptime(CarbonImmutable::parse('2026-11-01'), 99.9, CarbonImmutable::parse($through));
    }
    gapsOn('2026-12-01', '16:00');

    gapsReport();

    expect(gapsSection(gapsIssues($this->github)[0]['body'], 'Uptime'))->toContain($says);
})->with([
    'missing' => [null, 'November 2026\'s uptime is missing: help:record-uptime never kept a figure for it.'],
    'incomplete' => ['2026-11-28', 'November 2026\'s uptime is incomplete: it counts only to 28 November 2026 (99.9% to then).'],
]);

it('counts nothing from before going public', function () {
    gapsOn('2026-11-12');
    gapsViews('/articles/receive-a-delivery', 10);
    gapsOn('2026-12-01', '16:00');

    gapsReport();

    $measures = gapsSection(gapsIssues($this->github)[0]['body'], 'Measures');
    expect($measures)->toContain('| Over 85% | too few to judge<br>0 Still stuck of 10 Article views | before going public | before going public |')
        ->toContain('Counting started on 12 November 2026, when the help centre went public: nothing from before then is counted.')
        ->toContain('November 2026 is the part month before the three-month baseline, which runs from December 2026 to February 2027.');
});

it('raises nothing before going public, or for a month before it', function () {
    config(['help.going_public' => false]);
    gapsOn('2026-12-01', '16:00');

    [$exitCode, $output] = gapsReport();

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($output)->toContain('The help centre hasn\'t gone public, so nothing is counted and no Help Gaps Report is raised.')
        ->and($this->github->issues())->toBe([]);

    config(['help.going_public' => true]);
    gapsOn('2026-12-01');
    gapsViews('/', 1);
    gapsOn('2026-12-01', '16:00');

    [$exitCode, $output] = gapsReport();

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($output)->toContain('Nothing was counted in November 2026: the help centre went public on 1 December 2026.')
        ->and($this->github->issues())->toBe([]);
});

it('fails, saying so, without the issue desk\'s key or when GitHub can\'t be reached', function () {
    gapsOn('2026-11-04');
    gapsViews('/', 1);
    gapsOn('2026-12-01', '16:00');
    $this->github->unreachable();

    [$exitCode, $output] = gapsReport();

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('The Help Gaps Report for November 2026 couldn\'t be raised on gpl-ajackson/firstmilemanager-help: GitHub can\'t be reached');

    config(['help.issue_desk.token' => null]);
    [$exitCode, $output] = gapsReport();

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('HELP_GITHUB_ISSUES_TOKEN is not set on this server');
});
