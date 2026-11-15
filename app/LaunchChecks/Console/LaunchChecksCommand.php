<?php

namespace App\LaunchChecks\Console;

use App\LaunchChecks\CheckContext;
use App\LaunchChecks\GitHubAccounts;
use App\LaunchChecks\LaunchChecks;
use App\LaunchChecks\MainProject;
use App\LaunchChecks\MarkdownReport;
use App\LaunchChecks\Pictures\PicturesCheck;
use App\LaunchChecks\SourceReferences\MainProjectIssues;
use App\Site\GoingPublic;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * The automated Launch Checks over a content folder and a checkout of the main project, as
 * support-site CI runs them on every pull request (and Help Previews, the Release Check and the
 * Help Drift Report later). Exits 1 on any failure; reports never change the exit code.
 *
 * The Findability check (Launch Check 10) searches the content, so once the content folder
 * has a Findability Set the run needs the help centre's migrated database and Meilisearch, as
 * help:rebuild does. It changes neither: the database copy is rolled back and the index it
 * searches is a fresh one, deleted afterwards. The page checks (--pages: Launch Checks 7, 8 and
 * 11) load the content the same way and open every page in headless Chromium through the app.
 */
final class LaunchChecksCommand extends Command
{
    protected $signature = 'help:launch-checks
        {--main-project= : A checkout of gpl-ajackson/firstmilemanager at the ref under test (required)}
        {--main-project-ref=main : The ref that checkout is at, for the report}
        {--marketing-site= : A checkout of gpl-ajackson/firstmilemanager-marketing at main, whose src/data/site.ts the help centre\'s facts must match (Launch Check 9)}
        {--content= : The content folder (default: config help.content_path)}
        {--pictures= : The pictures folder, with each Picture\'s Recipe (default: config help.pictures_path)}
        {--templates= : The Article Templates folder (default: docs/writing/templates)}
        {--pull-request-description= : A file holding the pull request\'s description (runs the Help only check)}
        {--style-guide= : The Style Guide, whose header data lists its banned words (default: docs/writing/style-guide.md)}
        {--check-owner-logins : Look each Article Owner up on GitHub (GITHUB_TOKEN, when set, raises the rate limit)}
        {--take-pictures= : Retake Pictures from the main project\'s checkout and compare them (Launch Check 12): "changed" for those changed since --pictures-base, "all" for every one}
        {--pictures-base= : The git ref a pull request\'s Pictures are compared with, for --take-pictures=changed (its base branch)}
        {--picture-artefacts= : Where the Picture Check leaves before, after and difference images (default: storage/pictures/check)}
        {--pictures-only : Run Launch Check 12, Pictures, on its own (CI runs it in a job of its own, on the kind of machine the Pictures are taken on)}
        {--pages : Open every published page in headless Chromium: accessible and fits, privacy, and fast on the floor (Launch Checks 7, 8 and 11)}
        {--speed-at= : Also time each page template at this real address (Launch Check 11), through Cloudflare Access with CF_ACCESS_CLIENT_ID and CF_ACCESS_CLIENT_SECRET}
        {--at-launch : Fail the rules that hold from launch on, which are only reported before it (always on once going public is on)}
        {--markdown= : Also write the findings as the Markdown comment CI posts on the pull request}';

    protected $description = 'Run the automated Launch Checks over a content folder and a main-project checkout';

    public function handle(LaunchChecks $launchChecks): int
    {
        $mainProject = $this->stringOption('main-project');
        if ($mainProject === null || ! is_dir($mainProject)) {
            $this->error('Give --main-project=<path>: a checkout of gpl-ajackson/firstmilemanager at the ref under test'
                .($mainProject === null ? '.' : " ({$mainProject} is not a folder)."));

            return self::INVALID;
        }

        $content = $this->stringOption('content') ?? Config::string('help.content_path');
        $templates = $this->stringOption('templates') ?? base_path('docs/writing/templates');
        $descriptionFile = $this->stringOption('pull-request-description');
        if ($descriptionFile !== null && ! is_file($descriptionFile)) {
            $this->error("The pull request description file {$descriptionFile} doesn't exist.");

            return self::INVALID;
        }

        $takePictures = $this->stringOption('take-pictures');
        if ($takePictures !== null && ! in_array($takePictures, ['changed', 'all'], true)) {
            $this->error("--take-pictures is \"changed\" or \"all\", not \"{$takePictures}\".");

            return self::INVALID;
        }
        if ($takePictures === 'changed' && $this->stringOption('pictures-base') === null) {
            $this->error('--take-pictures=changed needs --pictures-base=<git ref>: the branch the pull request merges into.');

            return self::INVALID;
        }

        $speedAt = $this->stringOption('speed-at');
        if ($speedAt !== null && ! preg_match('~^https?://[^/\s]+/?$~', $speedAt)) {
            $this->error("--speed-at is the help centre's address, such as https://help.firstmilemanager.co.uk, not \"{$speedAt}\".");

            return self::INVALID;
        }

        $token = getenv('GITHUB_TOKEN');
        $readToken = config('help.main_project_read_token');
        $atLaunch = $this->option('at-launch') === true || GoingPublic::isOn();
        $picturesOnly = $this->option('pictures-only') === true;
        $findings = $launchChecks->run(new CheckContext(
            contentPath: rtrim($content, '/'),
            mainProject: new MainProject(rtrim($mainProject, '/')),
            templatesPath: rtrim($templates, '/'),
            pullRequestDescription: $descriptionFile === null ? null : (string) file_get_contents($descriptionFile),
            github: $this->option('check-owner-logins') === true ? new GitHubAccounts($token === false ? null : $token) : null,
            atLaunch: $atLaunch,
            issues: is_string($readToken) && $readToken !== '' ? new MainProjectIssues($readToken) : null,
            picturesPath: $this->stringOption('pictures'),
            styleGuidePath: $this->stringOption('style-guide') ?? base_path('docs/writing/style-guide.md'),
            takePictures: $takePictures,
            picturesBase: $this->stringOption('pictures-base'),
            pictureArtefacts: $this->stringOption('picture-artefacts'),
            marketingSitePath: ($marketingSite = $this->stringOption('marketing-site')) === null ? null : rtrim($marketingSite, '/'),
            checkPages: $this->option('pages') === true,
            speedAt: $speedAt,
        ), $picturesOnly ? [PicturesCheck::class] : null);

        $ref = $this->stringOption('main-project-ref') ?? 'main';
        $this->line(($picturesOnly ? 'Launch Check 12, Pictures,' : 'Launch Checks')." over {$content}, against the main project at {$mainProject} ({$ref})".($atLaunch ? ', at launch' : '').':');
        foreach ($findings->failures() as $finding) {
            $this->line("FAIL {$finding}");
        }
        foreach ($findings->reports() as $finding) {
            $this->line("REPORT {$finding}");
        }
        foreach ($findings->checksNotRun() as $notRun) {
            $this->line("Not run: {$notRun}.");
        }

        $failures = count($findings->failures());
        $reports = count($findings->reports());
        $summary = $failures.' '.Str::plural('failure', $failures).', '.$reports.' '.Str::plural('report', $reports).' (reports never fail).';
        $findings->passed() ? $this->info($summary) : $this->error($summary);

        $markdown = $this->stringOption('markdown');
        if ($markdown !== null) {
            file_put_contents($markdown, MarkdownReport::render($findings, $ref, $picturesOnly));
        }

        return $findings->passed() ? self::SUCCESS : self::FAILURE;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
