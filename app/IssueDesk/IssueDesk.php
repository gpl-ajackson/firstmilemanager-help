<?php

namespace App\IssueDesk;

use App\Content\ContentLibrary;
use App\Records\Records;
use Illuminate\Support\Facades\Config;

/**
 * The issue desk (spec #30, seam 4): opens and comments on issues in this repository through the
 * issue-only key (HELP_GITHUB_ISSUES_TOKEN on the server). Today it sends "doesn't match" votes
 * to their Article Owners: one open issue per Article, assigned to its owner, each later vote a
 * comment on it while it is open, and a new issue once it is closed. Votes are taken from
 * Records and marked sent one by one, so a vote GitHub didn't take waits for the next run.
 * The monthly reports (#67, #68) post through this module too. Each "doesn't match" issue,
 * comments and all, is deleted once it was opened more than Records::KEEP_COMMENTS_MONTHS ago, so
 * the copies of readers' comments are kept no longer than the comments themselves (rule 11).
 */
final readonly class IssueDesk
{
    public function __construct(private Records $records, private ContentLibrary $library) {}

    public function sendDoesntMatchVotes(): DoesntMatchRun
    {
        $votes = $this->records->doesntMatchVotesToSend();
        if ($votes === []) {
            return new DoesntMatchRun([], 0);
        }
        $issues = $this->issues();
        if ($issues === null) {
            return new DoesntMatchRun([], count($votes), 'HELP_GITHUB_ISSUES_TOKEN is not set on this server, so GitHub can\'t be asked');
        }

        /** @var array<string, int> $openIssues each Article's open issue, as found or opened this run */
        $openIssues = [];
        $sent = [];
        foreach ($votes as $vote) {
            $article = $this->library->articleNamed($vote->article);
            try {
                $openIssues[$vote->article] ??= $issues->openIssueMarked(DoesntMatchIssue::marker($vote->article)) ?? 0;
                if ($openIssues[$vote->article] === 0) {
                    $owner = $article->owner ?? Config::string('help.content_owner');
                    $opened = $issues->openIssue(DoesntMatchIssue::title($vote->article, $article), DoesntMatchIssue::body($vote, $article), [$owner]);
                    $openIssues[$vote->article] = $opened->number;
                    $sent[] = "Opened {$opened->address} for {$vote->article}, assigned to {$owner}";
                } else {
                    $address = $issues->comment($openIssues[$vote->article], DoesntMatchIssue::comment($vote));
                    $sent[] = "Commented {$address} on {$vote->article}";
                }
            } catch (IssueDeskUnavailable $e) {
                return new DoesntMatchRun($sent, count($votes) - count($sent), $e->getMessage());
            }
            $this->records->sentToIssue($vote->reference);
        }

        return new DoesntMatchRun($sent, 0);
    }

    /**
     * Deletes every "doesn't match" issue, open or closed, opened before the first day the
     * help centre still keeps comments from (Records::KEEP_COMMENTS_MONTHS, counted in UK days as
     * the nightly pruning counts them). Later votes' comments on it go with it, a little sooner.
     */
    public function deleteOldDoesntMatchIssues(): DeletedIssuesRun
    {
        $issues = $this->issues();
        if ($issues === null) {
            return new DeletedIssuesRun([], 'HELP_GITHUB_ISSUES_TOKEN is not set on this server, so GitHub can\'t be asked');
        }

        $keepFrom = Records::today()->subMonthsNoOverflow(Records::KEEP_COMMENTS_MONTHS);
        $deleted = [];
        try {
            foreach ($issues->issuesMarkedOpenedBefore(DoesntMatchIssue::MARKER_PREFIX, $keepFrom) as $issue) {
                $issues->delete($issue['id']);
                $deleted[] = "Deleted issue #{$issue['number']}, opened before {$keepFrom->toDateString()}";
            }
        } catch (IssueDeskUnavailable $e) {
            return new DeletedIssuesRun($deleted, $e->getMessage());
        }

        return new DeletedIssuesRun($deleted);
    }

    /** This repository's issues through the issue-only key, or null when the server has none. */
    private function issues(): ?GitHubIssues
    {
        $token = Config::get('help.issue_desk.token');

        return is_string($token) && $token !== ''
            ? new GitHubIssues(Config::string('help.issue_desk.repository'), $token)
            : null;
    }
}
