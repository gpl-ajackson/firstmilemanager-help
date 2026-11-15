<?php

use App\IssueDesk\DoesntMatchIssue;
use App\Records\Records;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Support\FakeGitHubIssues;

/*
 * "Doesn't match" issues are kept no longer than the comments they copy (rule 11; spec #30,
 * "Keeping"): every night each one opened more than six months ago is deleted from GitHub, open
 * or closed, with the later votes' comments on it. The help centre keeps comments 6 months
 * (Records::KEEP_COMMENTS_MONTHS), and the marketing site's privacy notice says the GitHub copies
 * go after the same 6 months. GitHub is faked at the issue desk.
 */

beforeEach(function () {
    $this->github = FakeGitHubIssues::start();
});

/** Moves the clock to a moment in the UK. */
function issueKeepingAt(string $moment): void
{
    test()->travelTo(CarbonImmutable::parse($moment, Records::TIMEZONE));
}

/** A "doesn't match" issue for an Article, opened now, as the issue desk opens it. */
function doesntMatchIssueOpened(FakeGitHubIssues $github, string $article): int
{
    $github->raised("Doesn't match what readers see: {$article}", DoesntMatchIssue::marker($article)."\nA reader chose \"It doesn't match what I see\".\n\n```text\nThe button has moved.\n```");

    return count($github->issues()) + count($github->deleted());
}

/** @return array{0: int, 1: string} the exit code and output */
function deleteOldDoesntMatchIssues(): array
{
    $exitCode = Artisan::call('help:delete-old-doesnt-match-issues');

    return [$exitCode, Artisan::output()];
}

it('runs every night after the pruning', function () {
    $runs = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => Str::contains((string) $event->command, 'help:delete-old-doesnt-match-issues'));

    expect($runs)->toHaveCount(1)
        ->and($runs->first()->expression)->toBe('45 3 * * *')
        ->and($runs->first()->timezone)->toBe(Records::TIMEZONE);
});

it('deletes "doesn\'t match" issues opened over six months ago, open or closed, and keeps the rest', function () {
    issueKeepingAt('2026-05-02 10:00');
    $open = doesntMatchIssueOpened($this->github, 'receive-at-the-dock');
    $closed = doesntMatchIssueOpened($this->github, 'count-stock');
    $this->github->close($closed);
    $this->github->raised('Help gaps: April 2026', "<!-- help-gaps-report:2026-04 -->\nThe month's figures.", ['help-gaps-report']);
    $report = count($this->github->issues());
    issueKeepingAt('2026-05-03 00:30');
    $recent = doesntMatchIssueOpened($this->github, 'warehouse-layout');

    // Six months on, comments from 3 May are still kept, so only the issues from 2 May go.
    issueKeepingAt('2026-11-03 03:45');
    [$exitCode, $output] = deleteOldDoesntMatchIssues();

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($this->github->deleted())->toEqualCanonicalizing([$open, $closed])
        ->and(array_column($this->github->issues(), 'number'))->toBe([$report, $recent])
        ->and($output)->toContain("Deleted issue #{$open}, opened before 2026-05-03")
        ->toContain("Deleted issue #{$closed}, opened before 2026-05-03");

    issueKeepingAt('2026-11-04 03:45');
    deleteOldDoesntMatchIssues();

    expect($this->github->deleted())->toEqualCanonicalizing([$open, $closed, $recent])
        ->and(array_column($this->github->issues(), 'number'))->toBe([$report]);
});

it('says so and asks GitHub to delete nothing when no issue is old enough', function () {
    issueKeepingAt('2026-10-20 10:00');
    doesntMatchIssueOpened($this->github, 'receive-at-the-dock');

    issueKeepingAt('2026-11-03 03:45');
    [$exitCode, $output] = deleteOldDoesntMatchIssues();

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($output)->toContain('No "doesn\'t match" issues are old enough to delete.')
        ->and($this->github->deleted())->toBe([])
        ->and($this->github->issues())->toHaveCount(1);
});

it('fails, saying why, when GitHub won\'t delete, and deletes on a later night', function () {
    issueKeepingAt('2026-05-02 10:00');
    $issue = doesntMatchIssueOpened($this->github, 'receive-at-the-dock');
    $this->github->refusesDeletes();

    issueKeepingAt('2026-11-03 03:45');
    [$exitCode, $output] = deleteOldDoesntMatchIssues();

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('Old "doesn\'t match" issues are left for the next run: GitHub wouldn\'t delete the issue (fixture-user does not have the correct permissions to execute `DeleteIssue`)')
        ->and($this->github->issues())->toHaveCount(1);

    $this->github->refusesDeletes(false);
    issueKeepingAt('2026-11-04 03:45');
    [$exitCode] = deleteOldDoesntMatchIssues();

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($this->github->deleted())->toBe([$issue]);
});

it('fails, naming the key, when the server has no issue key', function () {
    config(['help.issue_desk.token' => null]);

    [$exitCode, $output] = deleteOldDoesntMatchIssues();

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('HELP_GITHUB_ISSUES_TOKEN is not set')
        ->and($this->github->requests)->toBe(0);
});

it('fails, saying why, when GitHub can\'t be reached', function () {
    $this->github->unreachable();

    [$exitCode, $output] = deleteOldDoesntMatchIssues();

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('Old "doesn\'t match" issues are left for the next run: GitHub can\'t be reached');
});
