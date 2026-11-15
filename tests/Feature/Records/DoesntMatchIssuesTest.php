<?php

use App\Records\Records;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeGitHubIssues;
use Tests\Support\ReaderPage;

/*
 * "Doesn't match" issues (spec #30, user stories 70 to 72; seam 4): a reader's "It doesn't match
 * what I see" reaches the Article's owner the same day, as an issue on this repository assigned
 * to them, holding the vote's comment and saying when rule 4's five business days start. While
 * that issue is open, further votes on the Article are added to it as comments; once it is
 * closed, the next vote opens a new one. GitHub is faked at the issue desk.
 */

beforeEach(function () {
    config(['help.going_public' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:02', 'Europe/London'));
    $this->github = FakeGitHubIssues::start();

    content()
        ->article('receive-at-the-dock', ['title' => 'Receive a delivery', 'owner' => 'sam-trentside', 'checked_on' => '2026-10-24', 'related_articles' => ['count-stock', 'example-reference']])
        ->article('count-stock', ['title' => 'Count stock', 'owner' => 'priya-owner', 'related_articles' => ['receive-at-the-dock', 'example-reference']])
        ->article('example-reference', ['type' => 'Reference'])
        ->article('follow-your-deliveries', ['title' => 'Follow your deliveries', 'audience_tier' => 'Client', 'team_roles' => null, 'owner' => 'sam-trentside', 'related_articles' => ['see-your-stock', 'see-your-orders']])
        ->article('see-your-stock', ['audience_tier' => 'Client', 'team_roles' => null])
        ->article('see-your-orders', ['audience_tier' => 'Client', 'team_roles' => null])
        ->publish();
});

/**
 * A reader answers No on an Article's page and gives a Feedback Reason (by its recorded value)
 * and comment, as a browser posts the two plain forms.
 */
function voteNo(string $article, ?string $reason = "It doesn't match what I see", ?string $comment = null): void
{
    $headers = ['User-Agent' => A_READER, 'Origin' => rtrim(url('/'), '/'), 'Sec-Fetch-Site' => 'same-origin'];
    $page = ReaderPage::of(test()->withHeaders($headers)->post("/feedback/{$article}", ['answer' => 'no']));
    if ($reason !== null || $comment !== null) {
        test()->withHeaders($headers)->post("/feedback/{$article}/reason", array_filter([
            'vote' => (string) $page->value('.feedback-reason input[name=vote]'),
            'reason' => $reason,
            'comment' => $comment,
        ], fn (?string $value): bool => $value !== null))->assertOk();
    }
}

/** What the server's scheduler runs to send "doesn't match" votes to the issue desk. */
function doesntMatchRun(): Event
{
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        fn (Event $event): bool => str_contains((string) $event->command, 'help:doesnt-match-issues'),
    ));
    expect($events)->toHaveCount(1);

    return $events[0];
}

/** @return array{0: int, 1: string} the exit code and output */
function sendDoesntMatch(): array
{
    $exitCode = Artisan::call('help:doesnt-match-issues');

    return [$exitCode, Artisan::output()];
}

it('opens an issue for the Article Owner within the day, with the comment and rule 4\'s clock', function () {
    voteNo('receive-at-the-dock', comment: 'The Receive button is called Book in now.');

    // The scheduler next runs the issue desk later the same day, a few minutes after the vote.
    $next = CarbonImmutable::instance(doesntMatchRun()->nextRunDate(now()))->setTimezone(Records::TIMEZONE);
    expect($next->toDateString())->toBe('2026-11-03')
        ->and($next->lessThanOrEqualTo(now()->addMinutes(5)))->toBeTrue();
    $this->travelTo($next);
    expect(doesntMatchRun()->isDue(app()))->toBeTrue();

    [$exitCode, $output] = sendDoesntMatch();

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($output)->toContain('Opened https://github.com/gpl-ajackson/firstmilemanager-help/issues/1 for receive-at-the-dock, assigned to sam-trentside');
    $issues = $this->github->issues();
    expect($issues)->toHaveCount(1)
        ->and($issues[0]['title'])->toBe('Doesn\'t match what readers see: Receive a delivery')
        ->and($issues[0]['assignees'])->toBe(['sam-trentside'])
        ->and($issues[0]['state'])->toBe('open')
        ->and($issues[0]['body'])->toStartWith('<!-- help-doesnt-match:receive-at-the-dock -->')
        ->toContain('A reader chose "It doesn\'t match what I see" on [Receive a delivery]('.url('/articles/receive-at-the-dock').') (`content/articles/receive-at-the-dock.md`, Staff).')
        ->toContain('- Voted on 3 November 2026, when the Article was last checked on 24 October 2026.')
        ->toContain("```text\nThe Receive button is called Book in now.\n```")
        ->toContain('Rule 4\'s five business days start when you confirm the mismatch, not when this issue was opened.')
        ->toContain('If nothing is wrong, close this issue with a note saying why.')
        ->and($issues[0]['comments'])->toBe([]);
});

it('puts three votes on one Article into one issue and two comments', function () {
    voteNo('receive-at-the-dock', comment: 'First');
    voteNo('receive-at-the-dock', comment: 'Second');
    sendDoesntMatch();
    $this->travel(11)->minutes();
    voteNo('receive-at-the-dock', comment: 'Third');

    [$exitCode] = sendDoesntMatch();

    expect($exitCode)->toBe(Command::SUCCESS);
    $issues = $this->github->issues();
    expect($issues)->toHaveCount(1)
        ->and($issues[0]['comments'])->toHaveCount(2)
        ->and($issues[0]['comments'][1])->toStartWith('Another reader says this Article doesn\'t match what they see.')
        ->toContain('- Voted on 3 November 2026, when the Article was last checked on 24 October 2026.')
        ->toContain("```text\nThird\n```");
    $comments = implode("\n", [$issues[0]['body'], ...$issues[0]['comments']]);
    foreach (['First', 'Second', 'Third'] as $comment) {
        expect(substr_count($comments, "```text\n{$comment}\n```"))->toBe(1);
    }
});

it('opens one issue per Article, each for its own owner', function () {
    voteNo('receive-at-the-dock');
    voteNo('count-stock', comment: 'The Count button has moved.');

    sendDoesntMatch();

    $issues = collect($this->github->issues())->keyBy('title');
    expect($issues->keys()->sort()->values()->all())->toBe([
        'Doesn\'t match what readers see: Count stock',
        'Doesn\'t match what readers see: Receive a delivery',
    ])
        ->and($issues['Doesn\'t match what readers see: Count stock']['assignees'])->toBe(['priya-owner'])
        ->and($issues['Doesn\'t match what readers see: Receive a delivery']['body'])->toContain('The reader added no comment.');
});

it('opens a new issue for the next vote once the issue is closed', function () {
    voteNo('receive-at-the-dock', comment: 'Before');
    sendDoesntMatch();
    $this->github->close(1);
    $this->travelTo(CarbonImmutable::parse('2026-11-12 15:31', 'Europe/London'));

    voteNo('receive-at-the-dock', comment: 'After');
    sendDoesntMatch();

    $issues = $this->github->issues();
    expect($issues)->toHaveCount(2)
        ->and($issues[0]['comments'])->toBe([])
        ->and($issues[1]['state'])->toBe('open')
        ->and($issues[1]['assignees'])->toBe(['sam-trentside'])
        ->and($issues[1]['body'])->toContain("```text\nAfter\n```")
        ->toContain('- Voted on 12 November 2026');
});

it('keeps the vote when GitHub can\'t be reached, and opens the issue on a later run', function () {
    $this->github->unreachable();
    voteNo('receive-at-the-dock', comment: 'Still says Receive.');

    [$exitCode, $output] = sendDoesntMatch();

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('1 "doesn\'t match" vote waits for the next run: GitHub can\'t be reached')
        ->and($this->github->issues())->toBe([])
        ->and(array_map(fn ($vote): array => [$vote->reason?->value, $vote->comment], app(Records::class)->votes(now(), now())))
        ->toBe([["It doesn't match what I see", 'Still says Receive.']]);

    $this->github->reachable();
    $this->travel(5)->minutes();
    [$exitCode] = sendDoesntMatch();
    sendDoesntMatch();

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($this->github->issues())->toHaveCount(1)
        ->and($this->github->issues()[0]['body'])->toContain("```text\nStill says Receive.\n```")
        ->and($this->github->issues()[0]['comments'])->toBe([]);
});

it('sends nothing for other answers and reasons, and asks GitHub nothing when there is nothing to send', function () {
    $headers = ['User-Agent' => A_READER, 'Origin' => rtrim(url('/'), '/'), 'Sec-Fetch-Site' => 'same-origin'];
    $this->withHeaders($headers)->post('/feedback/receive-at-the-dock', ['answer' => 'yes'])->assertOk();
    voteNo('receive-at-the-dock', reason: null);
    voteNo('receive-at-the-dock', reason: "It didn't answer my question", comment: 'Not what I asked.');
    voteNo('count-stock', reason: 'Something else');

    [$exitCode, $output] = sendDoesntMatch();

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($output)->toContain('No "doesn\'t match" votes to send.')
        ->and($this->github->requests)->toBe(0);
    Http::assertNothingSent();
});

it('says a client gave the vote on a Client-tier Article', function () {
    voteNo('follow-your-deliveries', comment: 'My screen shows Orders, not Deliveries.');

    sendDoesntMatch();

    expect($this->github->issues()[0]['body'])
        ->toContain('on [Follow your deliveries]('.url('/articles/follow-your-deliveries').') (`content/articles/follow-your-deliveries.md`, Client tier: the reader is one of the Operator\'s clients).');
});

it('keeps a comment as the reader\'s words, never as GitHub markup', function () {
    voteNo('receive-at-the-dock', comment: "@sam-trentside see #1\n```\n<b>loud</b>");

    sendDoesntMatch();

    expect($this->github->issues()[0]['body'])->toContain("````text\n@sam-trentside see #1\n```\n<b>loud</b>\n````");
});

it('assigns the Content Owner when the Article is no longer in the help centre', function () {
    voteNo('count-stock');
    content()
        ->article('receive-at-the-dock', ['title' => 'Receive a delivery', 'owner' => 'sam-trentside', 'related_articles' => ['example-reference', 'follow-your-deliveries']])
        ->article('example-reference', ['type' => 'Reference'])
        ->article('follow-your-deliveries', ['audience_tier' => 'Client', 'team_roles' => null, 'related_articles' => ['see-your-stock', 'see-your-orders']])
        ->article('see-your-stock', ['audience_tier' => 'Client', 'team_roles' => null])
        ->article('see-your-orders', ['audience_tier' => 'Client', 'team_roles' => null])
        ->publish();

    sendDoesntMatch();

    $issue = $this->github->issues()[0];
    expect($issue['title'])->toBe('Doesn\'t match what readers see: count-stock')
        ->and($issue['assignees'])->toBe(['gpl-ajackson'])
        ->and($issue['body'])->toContain('There is no Article named `count-stock` in the help centre now, so this is assigned to the Content Owner.');
});

it('waits, saying which key to set, when the server has no issue key', function () {
    config(['help.issue_desk.token' => null]);
    voteNo('receive-at-the-dock');

    [$exitCode, $output] = sendDoesntMatch();

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('1 "doesn\'t match" vote waits for the next run: HELP_GITHUB_ISSUES_TOKEN is not set')
        ->and($this->github->requests)->toBe(0);

    config(['help.issue_desk.token' => FakeGitHubIssues::TOKEN]);
    sendDoesntMatch();

    expect($this->github->issues())->toHaveCount(1);
});
