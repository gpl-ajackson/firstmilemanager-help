<?php

namespace App\LaunchChecks\Console;

use App\IssueDesk\GitHubIssues;
use App\IssueDesk\IssueDeskUnavailable;
use App\LaunchChecks\CheckContext;
use App\LaunchChecks\Findings;
use App\LaunchChecks\Glossary\GlossaryCheck;
use App\LaunchChecks\Glossary\WeeklyGlossaryReport;
use App\LaunchChecks\MainProject;
use App\Records\Records;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * The Glossary Check (Launch Check 4) on its own, as the weekly workflow runs it against the
 * main project's main (.github/workflows/glossary-check.yml). With --post-to it posts what it
 * found to the Content Owner: a comment on the open Glossary Check issue, or a new issue
 * assigned to them. Exits 1 when an entry fails, or when the findings couldn't be posted.
 */
final class GlossaryCheckCommand extends Command
{
    protected $signature = 'help:glossary-check
        {--main-project= : A checkout of gpl-ajackson/firstmilemanager (required)}
        {--main-project-ref=main : The ref that checkout is at, for the report}
        {--content= : The content folder (default: config help.content_path)}
        {--post-to= : Post the findings to the Content Owner on this repository\'s issues (owner/name), with GITHUB_TOKEN}';

    protected $description = 'Run the Glossary Check on its own and post its findings to the Content Owner';

    public function handle(GlossaryCheck $check): int
    {
        $mainProject = $this->stringOption('main-project');
        if ($mainProject === null || ! is_dir($mainProject)) {
            $this->error('Give --main-project=<path>: a checkout of gpl-ajackson/firstmilemanager'
                .($mainProject === null ? '.' : " ({$mainProject} is not a folder)."));

            return self::INVALID;
        }
        $repository = $this->stringOption('post-to');
        $token = getenv('GITHUB_TOKEN');
        $token = is_string($token) && $token !== '' ? $token : null;
        if ($repository !== null && $token === null) {
            $this->error("Set GITHUB_TOKEN to a token that can write issues on {$repository}, or leave out --post-to.");

            return self::FAILURE;
        }

        $content = $this->stringOption('content') ?? Config::string('help.content_path');
        $ref = $this->stringOption('main-project-ref') ?? 'main';
        $findings = new Findings;
        $check->run(new CheckContext(
            contentPath: rtrim($content, '/'),
            mainProject: new MainProject(rtrim($mainProject, '/')),
            templatesPath: base_path('docs/writing/templates'),
        ), $findings);

        $this->line("Glossary Check over {$content}, against the main project at {$mainProject} ({$ref}):");
        foreach ($findings->failures() as $finding) {
            $this->line("FAIL {$finding}");
        }
        foreach ($findings->reports() as $finding) {
            $this->line("REPORT {$finding}");
        }
        $failures = count($findings->failures());
        $reports = count($findings->reports());
        $this->line($failures.' '.Str::plural('failure', $failures).', '.$reports.' '.Str::plural('report', $reports).' (reports never fail).');

        if ($repository !== null) {
            if ($failures + $reports === 0) {
                $this->line('Nothing to report this week, so nothing was posted.');
            } elseif (! $this->post(new GitHubIssues($repository, $token), $findings, $ref)) {
                return self::FAILURE;
            }
        }

        return $findings->passed() ? self::SUCCESS : self::FAILURE;
    }

    private function post(GitHubIssues $issues, Findings $findings, string $ref): bool
    {
        $day = Records::today();
        try {
            $open = $issues->openIssueMarked(WeeklyGlossaryReport::MARKER);
            $address = $open === null
                ? $issues->open(WeeklyGlossaryReport::TITLE, WeeklyGlossaryReport::issueBody($findings, $ref, $day), [Config::string('help.content_owner')])
                : $issues->comment($open, WeeklyGlossaryReport::comment($findings, $ref, $day));
        } catch (IssueDeskUnavailable $e) {
            $this->error("The findings couldn't be posted to {$issues->repository()}: {$e->getMessage()}");

            return false;
        }
        $this->info("Posted the Glossary Check's findings: {$address}");

        return true;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
