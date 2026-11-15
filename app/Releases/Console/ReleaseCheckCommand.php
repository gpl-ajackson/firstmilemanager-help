<?php

namespace App\Releases\Console;

use App\LaunchChecks\CheckContext;
use App\LaunchChecks\GitHubAccounts;
use App\LaunchChecks\MainProject;
use App\LaunchChecks\SourceReferences\MainProjectIssues;
use App\Releases\Check\InvalidRelease;
use App\Releases\Check\PullRequests;
use App\Releases\Check\ReleaseCheckReport;
use App\Releases\Releases;
use App\Site\GoingPublic;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * The Release Check (ADR-0003; plan S7): before AJ promotes the main project to its demo branch
 * (a FMM Release), every pull request merged since the last release with its help status,
 * the draft Release Note from the Next Release folder, and the Launch Checks with every Picture
 * retaken against the version being released, ending with whether help is complete for this
 * release. Exits 0 when it is, 1 when it isn't, 2 when it can't start.
 *
 * Stockist is read only through its repository (rule 21): a checkout at the version being
 * released, fetched with its whole history so origin/demo is there, and its pull requests on
 * GitHub with FMM_READ_TOKEN. Help Proposals are read with GITHUB_TOKEN. The Launch Checks
 * need what help:launch-checks needs: the migrated help-centre database and Meilisearch for the
 * Findability check, and Postgres for the picture tool; with --marketing-site, a checkout of the
 * marketing site at main for the facts. Nothing is written but --markdown.
 */
final class ReleaseCheckCommand extends Command
{
    protected $signature = 'help:release-check
        {--main-project= : A checkout of gpl-ajackson/firstmilemanager at the version being released, with its history and origin/demo (required)}
        {--main-project-ref= : The ref that checkout is at, for the report (default: its commit)}
        {--last-release=origin/demo : Where the last FMM Release is in that checkout}
        {--date= : The FMM Release\'s date for the draft Release Note, YYYY-MM-DD (default: today in the UK)}
        {--marketing-site= : A checkout of gpl-ajackson/firstmilemanager-marketing at main, whose src/data/site.ts the help centre\'s facts must match (Launch Check 9)}
        {--content= : The content folder (default: config help.content_path)}
        {--pictures= : The pictures folder, with each Picture\'s Recipe (default: config help.pictures_path)}
        {--templates= : The Article Templates folder (default: docs/writing/templates)}
        {--style-guide= : The Style Guide (default: docs/writing/style-guide.md)}
        {--picture-artefacts= : Where the Picture Check leaves before, after and difference images (default: storage/pictures/check)}
        {--check-owner-logins : Look each Article Owner up on GitHub, as CI does}
        {--markdown= : Also write the Release Check as Markdown, for the workflow\'s summary}';

    protected $description = 'Check that help is complete for a FMM Release: changes since the last release, the draft Release Note and the Launch Checks';

    public function handle(Releases $releases): int
    {
        $mainProject = $this->stringOption('main-project');
        if ($mainProject === null || ! is_dir($mainProject)) {
            $this->error('Give --main-project=<path>: a checkout of gpl-ajackson/firstmilemanager at the version being released, fetched with its history'
                .($mainProject === null ? '.' : " ({$mainProject} is not a folder)."));

            return self::INVALID;
        }
        $date = Releases::releaseDate($this->stringOption('date'));
        if ($date === null) {
            $this->error('"'.$this->stringOption('date').'" is not a date written YYYY-MM-DD.');

            return self::INVALID;
        }

        $readToken = config('help.main_project_read_token');
        $readToken = is_string($readToken) && $readToken !== '' ? $readToken : null;
        $githubToken = getenv('GITHUB_TOKEN');
        $githubToken = is_string($githubToken) && $githubToken !== '' ? $githubToken : null;
        $lastRelease = $this->stringOption('last-release') ?? 'origin/demo';

        try {
            $check = $releases->checkRelease(
                new CheckContext(
                    contentPath: rtrim($this->stringOption('content') ?? Config::string('help.content_path'), '/'),
                    mainProject: new MainProject(rtrim($mainProject, '/')),
                    templatesPath: rtrim($this->stringOption('templates') ?? base_path('docs/writing/templates'), '/'),
                    github: $this->option('check-owner-logins') === true ? new GitHubAccounts($githubToken) : null,
                    atLaunch: GoingPublic::isOn(),
                    issues: $readToken === null ? null : new MainProjectIssues($readToken),
                    picturesPath: $this->stringOption('pictures'),
                    styleGuidePath: $this->stringOption('style-guide') ?? base_path('docs/writing/style-guide.md'),
                    takePictures: 'all',
                    pictureArtefacts: $this->stringOption('picture-artefacts'),
                    marketingSitePath: ($marketingSite = $this->stringOption('marketing-site')) === null ? null : rtrim($marketingSite, '/'),
                ),
                $lastRelease,
                $date,
                new PullRequests($readToken, $githubToken),
            );
        } catch (InvalidRelease $invalid) {
            $this->error("The Release Check can't start: {$invalid->getMessage()}.");
            if ($lastRelease === 'origin/demo') {
                $this->line('Check the main project out with its whole history (fetch-depth: 0) so origin/demo is there. Before the first promotion to demo, give the commit last released with --last-release.');
            }

            return self::INVALID;
        }

        $report = new ReleaseCheckReport($check, $this->stringOption('main-project-ref') ?? '');
        foreach ($report->lines() as $line) {
            $this->line($line);
        }
        $markdown = $this->stringOption('markdown');
        if ($markdown !== null) {
            file_put_contents($markdown, $report->markdown());
        }

        return $check->isComplete() ? self::SUCCESS : self::FAILURE;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
