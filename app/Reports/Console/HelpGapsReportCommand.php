<?php

namespace App\Reports\Console;

use App\IssueDesk\GitHubIssues;
use App\IssueDesk\IssueDeskUnavailable;
use App\Records\Records;
use App\Reports\HelpDriftReport;
use App\Reports\HelpGapsReport;
use App\Reports\ReportMonth;
use App\Site\GoingPublic;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * The Help Gaps Report, as the server raises it on the first working day of each month (spec #30,
 * Further Notes 3; scheduled in routes/console.php): one issue on this repository through the
 * issue desk's key, assigned to the Content Owner, labelled help-gaps-report and linking the
 * month's Help Drift Report. Exactly one issue a month: once the month's is raised, open or
 * closed, later runs leave it be.
 *
 * The Help Drift Report's workflow raises its issue the same morning. With --wait-until, a run
 * that doesn't find it yet raises nothing, so a later run can link it; from that time on the
 * report is raised without it, saying so. Nothing is raised before going public, or for a month
 * that ended before counting started. --print prints the report instead of raising it.
 *
 * Exits 1 when the report couldn't be raised (no key, or GitHub didn't take it), so the next
 * scheduled run tries again.
 */
final class HelpGapsReportCommand extends Command
{
    protected $signature = 'help:gaps-report
        {--month= : The month to report on, YYYY-MM (default: the month that ended before today, UK time)}
        {--wait-until= : While the month\'s Help Drift Report isn\'t raised, raise nothing before this time today, HH:MM UK time}
        {--print : Print the report instead of raising it}';

    protected $description = 'Make the monthly Help Gaps Report from the records and raise it as an issue for the Content Owner';

    public function handle(HelpGapsReport $gaps, Records $records): int
    {
        $today = Records::today();
        $waitUntil = $this->option('wait-until');
        try {
            $month = is_string($this->option('month')) && $this->option('month') !== '' ? ReportMonth::of($this->option('month')) : ReportMonth::endedBefore($today);
            if (is_string($waitUntil) && preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $waitUntil) !== 1) {
                throw new InvalidArgumentException("--wait-until is a time written HH:MM, such as 16:00, not \"{$waitUntil}\".");
            }
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage());

            return self::INVALID;
        }

        if (! GoingPublic::isOn()) {
            $this->info('The help centre hasn\'t gone public, so nothing is counted and no Help Gaps Report is raised.');

            return self::SUCCESS;
        }
        $countingSince = $records->countingSince();
        if ($countingSince === null || $countingSince->greaterThan($month->end())) {
            $this->info("Nothing was counted in {$month->name()}: ".($countingSince === null
                ? 'nothing has been recorded since the help centre went public.'
                : "the help centre went public on {$countingSince->format('j F Y')}."));

            return self::SUCCESS;
        }

        $issues = $this->issues();
        if ($this->option('print') === true) {
            try {
                $drift = $issues?->issueMarked(HelpDriftReport::marker($month), HelpDriftReport::LABEL);
            } catch (IssueDeskUnavailable) {
                $issues = $drift = null;
            }
            $this->line($gaps->make($month, $today, $drift, $issues !== null)->body());

            return self::SUCCESS;
        }
        if ($issues === null) {
            $this->error('HELP_GITHUB_ISSUES_TOKEN is not set on this server, so the Help Gaps Report can\'t be raised.');

            return self::FAILURE;
        }

        try {
            $existing = $issues->issueMarked(HelpGapsReport::marker($month), HelpGapsReport::LABEL);
            if ($existing !== null) {
                $this->info("The Help Gaps Report for {$month->name()} is already raised: #{$existing}.");

                return self::SUCCESS;
            }
            $drift = $issues->issueMarked(HelpDriftReport::marker($month), HelpDriftReport::LABEL);
            if ($drift === null && is_string($waitUntil) && CarbonImmutable::now(Records::TIMEZONE)->lessThan(CarbonImmutable::parse($today->toDateString().' '.$waitUntil, Records::TIMEZONE))) {
                $this->info("The Help Drift Report for {$month->name()} isn't raised yet, so the Help Gaps Report waits for a later run (until {$waitUntil}).");

                return self::SUCCESS;
            }
            $report = $gaps->make($month, $today, $drift);
            $address = $issues->open($report->title(), $report->body(), [Config::string('help.content_owner')], [HelpGapsReport::LABEL]);
        } catch (IssueDeskUnavailable $e) {
            $this->error("The Help Gaps Report for {$month->name()} couldn't be raised on {$issues->repository()}: {$e->getMessage()}");

            return self::FAILURE;
        }
        $this->info("Raised the Help Gaps Report for {$month->name()}: {$address}");

        return self::SUCCESS;
    }

    /** This repository's issues through the issue desk's key, or null when the server has none. */
    private function issues(): ?GitHubIssues
    {
        $token = Config::get('help.issue_desk.token');

        return is_string($token) && $token !== ''
            ? new GitHubIssues(Config::string('help.issue_desk.repository'), $token)
            : null;
    }
}
