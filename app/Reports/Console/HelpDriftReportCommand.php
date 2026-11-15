<?php

namespace App\Reports\Console;

use App\IssueDesk\GitHubIssues;
use App\IssueDesk\IssueDeskUnavailable;
use App\LaunchChecks\CheckContext;
use App\LaunchChecks\MainProject;
use App\LaunchChecks\SourceReferences\MainProjectIssues;
use App\Records\Records;
use App\Reports\Drift\DriftLinks;
use App\Reports\Drift\DriftReport;
use App\Reports\GitHubReader;
use App\Reports\HelpDriftReport;
use App\Reports\MainProjectHistory;
use App\Reports\ReportMonth;
use App\Reports\UnreadableHistory;
use App\Site\GoingPublic;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * The Help Drift Report, as its monthly workflow raises it (.github/workflows/help-drift-report.yml):
 * the content folder and Pictures of this repository, against a checkout of the main project's
 * main with its whole history, and the main project's issues and pull requests on GitHub with
 * the read-only key (FMM_READ_TOKEN). It prints the report; with --post-to it raises it as
 * the month's issue, assigned to the Content Owner and labelled help-drift-report, or, when
 * the month's issue is already there (a run by hand), adds the report to it as a comment.
 *
 * Exits 1 when part of the report couldn't be checked (it still says which) or it couldn't be
 * posted, so the workflow's run turns red.
 */
final class HelpDriftReportCommand extends Command
{
    protected $signature = 'help:drift-report
        {--main-project= : A checkout of gpl-ajackson/firstmilemanager at main, with its whole history (required)}
        {--main-project-ref=main : The branch that checkout is of, for the report}
        {--stockist= : A checkout of gpl-ajackson/stockist at main, for the terms both glossaries share}
        {--content= : The content folder (default: config help.content_path)}
        {--pictures= : The pictures folder, with each Picture\'s Recipe (default: config help.pictures_path)}
        {--take-pictures : Retake every Picture from the main project\'s checkout and compare them (the Picture Check; installs and seeds Stockist)}
        {--picture-artefacts= : Where the Picture Check leaves before, after and difference images (default: storage/pictures/check)}
        {--month= : The month to report on, YYYY-MM (default: the month that ended before today, UK time)}
        {--support-site-ref=main : The commit of this repository the report reads, for its links}
        {--run-url= : The workflow run, whose artefacts hold the Picture Check\'s images, for its links}
        {--post-to= : Raise the report as an issue on this repository (owner/name), with GITHUB_TOKEN}';

    protected $description = 'Make the monthly Help Drift Report and raise it as an issue for the Content Owner';

    public function handle(HelpDriftReport $drift): int
    {
        $mainProject = $this->stringOption('main-project');
        if ($mainProject === null || ! is_dir($mainProject)) {
            $this->error('Give --main-project=<path>: a checkout of gpl-ajackson/firstmilemanager at main, with its whole history'
                .($mainProject === null ? '.' : " ({$mainProject} is not a folder)."));

            return self::INVALID;
        }
        $today = Records::today();
        try {
            $month = $this->stringOption('month') === null ? ReportMonth::endedBefore($today) : ReportMonth::of((string) $this->stringOption('month'));
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage());

            return self::INVALID;
        }
        $repository = $this->stringOption('post-to');
        $token = getenv('GITHUB_TOKEN');
        $token = is_string($token) && $token !== '' ? $token : null;
        if ($repository !== null && $token === null) {
            $this->error("Set GITHUB_TOKEN to a token that can write issues on {$repository}, or leave out --post-to.");

            return self::FAILURE;
        }

        $mainProject = rtrim($mainProject, '/');
        $readToken = config('help.main_project_read_token');
        $readToken = is_string($readToken) && $readToken !== '' ? $readToken : null;
        $content = $this->stringOption('content') ?? Config::string('help.content_path');
        $ref = $this->stringOption('main-project-ref') ?? 'main';
        try {
            $commit = MainProjectHistory::of($mainProject)->head;
        } catch (UnreadableHistory) {
            $commit = $ref;
        }
        $supportSite = $repository ?? Config::string('help.issue_desk.repository');

        $report = $drift->make(
            month: $month,
            day: $today,
            context: new CheckContext(
                contentPath: rtrim($content, '/'),
                mainProject: new MainProject($mainProject),
                templatesPath: base_path('docs/writing/templates'),
                atLaunch: GoingPublic::isOn(),
                issues: $readToken === null ? null : new MainProjectIssues($readToken),
                picturesPath: $this->stringOption('pictures'),
                takePictures: $this->option('take-pictures') === true ? 'all' : null,
                pictureArtefacts: $this->stringOption('picture-artefacts'),
                stockistPath: ($stockist = $this->stringOption('stockist')) === null ? null : rtrim($stockist, '/'),
            ),
            links: new DriftLinks($supportSite, $this->stringOption('support-site-ref') ?? 'main', $commit, $this->stringOption('run-url')),
            stockist: $readToken === null ? null : new GitHubReader($readToken),
            supportSite: $token === null ? null : new GitHubReader($token),
            mainProjectRef: $ref,
        );

        $this->line($report->title());
        $this->line($report->body());

        if ($repository !== null && ! $this->post(new GitHubIssues($repository, (string) $token), $report)) {
            return self::FAILURE;
        }
        if (! $report->checkedEverything()) {
            $this->error('Part of the report couldn\'t be checked: see its "Not checked" lines.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function post(GitHubIssues $issues, DriftReport $report): bool
    {
        try {
            $existing = $issues->issueMarked(HelpDriftReport::marker($report->month), HelpDriftReport::LABEL);
            $address = $existing === null
                ? $issues->open($report->title(), $report->body(), [Config::string('help.content_owner')], [HelpDriftReport::LABEL])
                : $issues->comment($existing, "## Run again on {$report->day->format('j F Y')}\n\n".$report->body());
        } catch (IssueDeskUnavailable $e) {
            $this->error("The report couldn't be raised on {$issues->repository()}: {$e->getMessage()}");

            return false;
        }
        $this->info(($existing === null ? 'Raised' : 'Added again to').' the Help Drift Report for '.$report->month->name().": {$address}");

        return true;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
