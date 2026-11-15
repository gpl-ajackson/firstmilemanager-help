<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

/**
 * Runs the Launch Checks command (spec #30, seam 2) over a fixture content folder and a fixture
 * main-project checkout, as CI and a writer run it, and holds what it printed.
 *
 *     $run = launchChecks(content()->article('put-a-client-on-hold'))->run();
 *     expect($run->failures())->toBe([]);
 */
final class LaunchCheckRun
{
    private ?FixtureMainProject $mainProject = null;

    private ?string $templatesPath = null;

    private ?string $description = null;

    private bool $readsIssues = true;

    private ?string $styleGuidePath = null;

    private ?FixtureMarketingSite $marketingSite = null;

    private bool $hasMarketingSite = true;

    private ?FixtureMainProject $stockist = null;

    public int $exitCode = 0;

    public string $output = '';

    public ?string $markdown = null;

    public function __construct(private readonly FixtureContent $content) {}

    public function against(FixtureMainProject $mainProject): self
    {
        $this->mainProject = $mainProject;

        return $this;
    }

    /** The marketing site at main, for Launch Check 9's facts; by default one whose site.ts matches the default facts. */
    public function marketingSite(FixtureMarketingSite $marketingSite): self
    {
        $this->marketingSite = $marketingSite;

        return $this;
    }

    /** A checkout of Stockist, whose GLOSSARY.md the Glossary and Words checks read for shared terms (--stockist). */
    public function stockist(FixtureMainProject $stockist): self
    {
        $this->stockist = $stockist;

        return $this;
    }

    /** Runs without a checkout of the marketing site, as a writer may locally. */
    public function withoutMarketingSite(): self
    {
        $this->hasMarketingSite = false;

        return $this;
    }

    public function templates(FixtureTemplates|string $templates): self
    {
        $this->templatesPath = $templates instanceof FixtureTemplates ? $templates->path() : $templates;

        return $this;
    }

    /** The pull request's description, as CI passes it. */
    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /** Runs without FMM_READ_TOKEN, as a writer may locally, so issues can't be read. */
    public function withoutIssueToken(): self
    {
        $this->readsIssues = false;

        return $this;
    }

    /**
     * The Style Guide, as the foundation ticket (#70) writes docs/writing/style-guide.md: header
     * data listing its banned words, each a word or {word, say_instead}, then its prose. Without
     * one, the run has no Style Guide, as before #70.
     *
     * @param  list<string|array{word: string, say_instead?: string}>|null  $bannedWords  null writes $contents as the whole file
     */
    public function styleGuide(?array $bannedWords, ?string $contents = null): self
    {
        $this->styleGuidePath = (string) tempnam(sys_get_temp_dir(), 'help-style-guide-');
        file_put_contents($this->styleGuidePath, $contents ?? "---\n".Yaml::dump(['banned_words' => $bannedWords], 3)."---\n\n# Style Guide\n\nWrite plainly.\n");

        return $this;
    }

    /**
     * @param  array<string, mixed>  $options  More command options, such as ['--check-owner-logins' => true].
     */
    public function run(array $options = []): self
    {
        $markdownFile = tempnam(sys_get_temp_dir(), 'launch-checks-md-');
        $this->templatesPath ??= FixtureTemplates::make()->path();
        $this->content->withDefaultRelatedArticles();
        $this->mainProject ??= FixtureMainProject::make();
        $this->serveIssues($this->mainProject);
        $arguments = [
            '--content' => $this->content->path(),
            '--pictures' => $this->content->picturesPath(),
            '--main-project' => $this->mainProject->path(),
            '--templates' => $this->templatesPath,
            '--style-guide' => $this->styleGuidePath ?? sys_get_temp_dir().'/no-style-guide-'.getmypid(),
            '--markdown' => $markdownFile,
            ...($this->hasMarketingSite ? ['--marketing-site' => ($this->marketingSite ??= FixtureMarketingSite::make())->path()] : []),
            ...($this->stockist !== null ? ['--stockist' => $this->stockist->path()] : []),
            ...$options,
        ];
        if ($this->description !== null) {
            $descriptionFile = (string) tempnam(sys_get_temp_dir(), 'launch-checks-pr-');
            file_put_contents($descriptionFile, $this->description);
            $arguments['--pull-request-description'] = $descriptionFile;
        }

        $this->exitCode = Artisan::call('help:launch-checks', $arguments);
        $this->output = Artisan::output();
        $this->markdown = $markdownFile !== false && is_file($markdownFile) ? (string) file_get_contents($markdownFile) : null;

        return $this;
    }

    /**
     * GitHub's issues API for gpl-ajackson/firstmilemanager, answering from the fixture main project's
     * issues (404 for any other number), with the read-only key CI holds. A test's own
     * Http::fake() for the same address, made before run(), answers first.
     */
    private function serveIssues(FixtureMainProject $mainProject): void
    {
        config(['help.main_project_read_token' => $this->readsIssues ? 'fixture-read-token' : null]);

        Http::fake(['api.github.com/repos/gpl-ajackson/firstmilemanager/issues/*' => function (Request $request) use ($mainProject) {
            $number = (int) basename((string) parse_url($request->url(), PHP_URL_PATH));
            $issue = $mainProject->issues()[$number] ?? null;

            return $issue === null ? Http::response(['message' => 'Not Found'], 404) : Http::response($issue);
        }]);
    }

    /** The templates folder this run read. */
    public function templatesPath(): ?string
    {
        return $this->templatesPath;
    }

    /**
     * The FAIL lines, without the "FAIL " prefix; with $check, only that check's (rules
     * "<check>.<rule>"), for a test about one check run where another also fails, such as at launch.
     *
     * @return list<string>
     */
    public function failures(?string $check = null): array
    {
        $lines = $this->lines('FAIL ');

        return $check === null ? $lines : array_values(array_filter($lines, fn (string $line): bool => str_contains($line, " [{$check}.")));
    }

    /** @return list<string> The REPORT lines, without the "REPORT " prefix. */
    public function reports(): array
    {
        return $this->lines('REPORT ');
    }

    /** @return list<string> */
    private function lines(string $prefix): array
    {
        $lines = array_filter(
            array_map(trim(...), explode("\n", $this->output)),
            fn (string $line): bool => str_starts_with($line, $prefix),
        );

        return array_values(array_map(fn (string $line): string => substr($line, strlen($prefix)), $lines));
    }
}
