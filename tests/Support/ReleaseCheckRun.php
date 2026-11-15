<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Runs the Release Check (help:release-check) as its manual workflow does, from fixture
 * repositories (spec #30, Testing Decisions 8): a fixture content folder (this repository), a
 * fixture main-project checkout whose git history holds the merges since the last Stockist
 * Release (origin/demo), and GitHub faked at its boundary. The main project's pull requests are
 * served with the read-only key (FMM_READ_TOKEN), this repository's Help Proposals with the
 * workflow's own token (GITHUB_TOKEN); any other request fails the test.
 *
 *     $run = releaseCheck(content())
 *         ->mergedPullRequest(270, 'Earlier work', 'No help changes: tooling')
 *         ->stockistRelease()
 *         ->mergedPullRequest(275, 'Zone walk', 'Help: gpl-ajackson/firstmilemanager-help#81')
 *         ->helpProposal(81, 'merged')
 *         ->run(['--date' => '2026-11-04']);
 *     $run->changes();   // the lines under "Pull requests merged since the last FMM Release"
 */
final class ReleaseCheckRun
{
    public const string READ_TOKEN = 'fixture-read-token';

    public const string WORKFLOW_TOKEN = 'fixture-workflow-token';

    private const string STOCKIST = 'gpl-ajackson/firstmilemanager';

    private const string SUPPORT_SITE = 'gpl-ajackson/firstmilemanager-help';

    private FixtureMainProject $mainProject;

    /** @var list<array{kind: string, number?: int, message?: string}> what happened on main, in order */
    private array $history = [];

    /** @var array<int, array<string, mixed>> the main project's pull requests, as GitHub's API gives them */
    private array $pullRequests = [];

    /** @var array<string, int> commit => pull request, for GitHub's "pull requests of a commit" */
    private array $commitPullRequests = [];

    /** @var array<int, string> this repository's pull requests (Help Proposals): open, merged or closed */
    private array $helpProposals = [];

    private bool $readsStockist = true;

    private bool $reachable = true;

    private bool $marksRelease = true;

    private ?FixtureMarketingSite $marketingSite = null;

    private bool $hasMarketingSite = true;

    public int $exitCode = 0;

    public string $output = '';

    public ?string $markdown = null;

    /** @var list<string> every request that reached GitHub: "METHOD url (token)" */
    public array $requests = [];

    public function __construct(private readonly FixtureContent $content)
    {
        $this->mainProject = FixtureMainProject::make();
    }

    public function against(FixtureMainProject $mainProject): self
    {
        $this->mainProject = $mainProject;

        return $this;
    }

    /**
     * A pull request merged into the main project's main with its description as written: a
     * Help Proposal link, "No help changes: <reason>", or neither. GitHub's merge commit names
     * it ("Merge pull request #N from ..."), unless $namedInCommit is false, as a rebase merge
     * leaves it: then only GitHub knows which pull request the commit came from.
     */
    public function mergedPullRequest(int $number, string $title, string $body, string $author = 'gpl-ajackson', bool $namedInCommit = true): self
    {
        $this->pullRequests[$number] = [
            'number' => $number,
            'title' => $title,
            'body' => $body,
            'state' => 'closed',
            'merged_at' => '2026-10-20T10:00:00Z',
            'html_url' => 'https://github.com/'.self::STOCKIST."/pull/{$number}",
            'user' => ['login' => $author],
            'base' => ['ref' => 'main'],
        ];
        // GitHub's merge commit: the pull request on the first line, its title after a blank line.
        $this->history[] = ['kind' => 'pull request', 'number' => $number, 'message' => $namedInCommit
            ? "Merge pull request #{$number} from gpl-ajackson/change-{$number}\n\n{$title}"
            : $title];

        return $this;
    }

    /** A commit pushed straight to main, with no pull request. */
    public function pushedToMain(string $message): self
    {
        $this->history[] = ['kind' => 'push', 'message' => $message];

        return $this;
    }

    /** The last FMM Release: origin/demo is main as it is now. Without one, the release is the first commit. */
    public function stockistRelease(): self
    {
        $this->history[] = ['kind' => 'release'];

        return $this;
    }

    /** A main-project checkout with no origin/demo (fetched without it, or before the first promotion). */
    public function withoutDemoBranch(): self
    {
        $this->marksRelease = false;

        return $this;
    }

    /** A pull request of this repository (a Help Proposal): 'open', 'merged' or 'closed' (without merging). */
    public function helpProposal(int $number, string $state): self
    {
        $this->helpProposals[$number] = $state;

        return $this;
    }

    /** Runs without FMM_READ_TOKEN, so the main project's pull requests can't be read. */
    public function withoutStockistToken(): self
    {
        $this->readsStockist = false;

        return $this;
    }

    /** GitHub answers every request with a server error. */
    public function githubUnreachable(): self
    {
        $this->reachable = false;

        return $this;
    }

    /** The marketing site at main, for Launch Check 9's facts; by default one whose site.ts matches the default facts. */
    public function marketingSite(FixtureMarketingSite $marketingSite): self
    {
        $this->marketingSite = $marketingSite;

        return $this;
    }

    /** Runs without a checkout of the marketing site. */
    public function withoutMarketingSite(): self
    {
        $this->hasMarketingSite = false;

        return $this;
    }

    public function mainProjectPath(): string
    {
        return $this->mainProject->path();
    }

    /** @param  array<string, mixed>  $options  More command options, such as ['--date' => '2026-11-04']. */
    public function run(array $options = []): self
    {
        $this->content->withDefaultRelatedArticles();
        $this->commitHistory();
        $this->fakeGitHub();
        config(['help.main_project_read_token' => $this->readsStockist ? self::READ_TOKEN : null]);
        putenv('GITHUB_TOKEN='.self::WORKFLOW_TOKEN);
        $markdownFile = (string) tempnam(sys_get_temp_dir(), 'release-check-md-');

        try {
            $this->exitCode = Artisan::call('help:release-check', [
                '--content' => $this->content->path(),
                '--pictures' => $this->content->picturesPath(),
                '--main-project' => $this->mainProject->path(),
                '--templates' => FixtureTemplates::make()->path(),
                '--style-guide' => sys_get_temp_dir().'/no-style-guide-'.getmypid(),
                '--picture-artefacts' => FixtureRecipes::folder('release-check-pictures'),
                '--markdown' => $markdownFile,
                ...($this->hasMarketingSite ? ['--marketing-site' => ($this->marketingSite ??= FixtureMarketingSite::make())->path()] : []),
                ...$options,
            ]);
        } finally {
            putenv('GITHUB_TOKEN');
        }
        $this->output = Artisan::output();
        $this->markdown = is_file($markdownFile) && filesize($markdownFile) > 0 ? (string) file_get_contents($markdownFile) : null;
        @unlink($markdownFile);

        return $this;
    }

    /**
     * The lines of one part of what the command printed, by the first words of its heading,
     * each without its indent, up to the next heading (a line that doesn't start with spaces).
     *
     * @return list<string>
     */
    public function section(string $heading): array
    {
        $lines = preg_split('/\R/', $this->output) ?: [];
        $in = false;
        $section = [];
        foreach ($lines as $line) {
            if (! $in) {
                $in = str_starts_with($line, $heading);

                continue;
            }
            if ($line !== '' && ! str_starts_with($line, ' ')) {
                break;
            }
            if (trim($line) !== '') {
                $section[] = substr($line, 2);
            }
        }

        return $section;
    }

    /** @return list<string> The lines under "Pull requests merged since the last FMM Release". */
    public function changes(): array
    {
        return $this->section('Pull requests merged since the last FMM Release');
    }

    /** The command's last line: whether help is complete for this release. */
    public function verdict(): string
    {
        $lines = array_values(array_filter(preg_split('/\R/', trim($this->output)) ?: [], fn (string $line): bool => $line !== ''));

        return (string) end($lines);
    }

    /** Makes the fixture main project a git checkout of main whose history is the one described. */
    private function commitHistory(): void
    {
        $path = $this->mainProject->path();
        $git = fn (array $command) => Process::path($path)
            ->env(['GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.com', 'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.com'])
            ->run(['git', ...$command])->throw();

        $git(['init', '--quiet', '--initial-branch=main']);
        $git(['add', '--all']);
        $git(['commit', '--quiet', '--message', 'The main project as it was']);
        $history = $this->history;
        if ($this->marksRelease && ! in_array('release', array_column($history, 'kind'), true)) {
            array_unshift($history, ['kind' => 'release']);
        }

        foreach ($history as $index => $event) {
            if ($event['kind'] === 'release') {
                if ($this->marksRelease) {
                    $git(['update-ref', 'refs/remotes/origin/demo', 'HEAD']);
                }

                continue;
            }
            File::put("{$path}/change-{$index}.txt", "Change {$index}\n");
            if ($event['kind'] === 'push') {
                $git(['add', '--all']);
                $git(['commit', '--quiet', '--message', $event['message'] ?? '']);

                continue;
            }

            $number = (int) ($event['number'] ?? 0);
            $git(['checkout', '--quiet', '-b', "change-{$number}"]);
            $git(['add', '--all']);
            $git(['commit', '--quiet', '--message', "Work for pull request {$number}"]);
            $git(['checkout', '--quiet', 'main']);
            $git(['merge', '--quiet', '--no-ff', '--message', (string) ($event['message'] ?? ''), "change-{$number}"]);
            $this->commitPullRequests[trim($git(['rev-parse', 'HEAD'])->output())] = $number;
        }
    }

    private function fakeGitHub(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.github.com/*' => function (Request $request) {
            $authorization = $request->header('Authorization')[0] ?? '';
            $this->requests[] = $request->method().' '.$request->url().' ('.(preg_replace('/\A(Bearer|token) /', '', $authorization) ?: 'no token').')';
            if (! $this->reachable) {
                return Http::response(['message' => 'Server Error'], 502);
            }

            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $stockist = str_contains($authorization, self::READ_TOKEN);
            if (preg_match('#\A/repos/'.self::STOCKIST.'/pulls/(\d+)\z#', $path, $match) === 1 && $stockist) {
                $pull = $this->pullRequests[(int) $match[1]] ?? null;

                return $pull === null ? Http::response(['message' => 'Not Found'], 404) : Http::response($pull);
            }
            if (preg_match('#\A/repos/'.self::STOCKIST.'/commits/([0-9a-f]{40})/pulls\z#', $path, $match) === 1 && $stockist) {
                $number = $this->commitPullRequests[$match[1]] ?? null;

                return Http::response($number === null ? [] : [$this->pullRequests[$number]]);
            }
            if (preg_match('#\A/repos/'.self::STOCKIST.'/issues/(\d+)\z#', $path, $match) === 1 && $stockist) {
                $issue = $this->mainProject->issues()[(int) $match[1]] ?? null;

                return $issue === null ? Http::response(['message' => 'Not Found'], 404) : Http::response($issue);
            }
            if (preg_match('#\A/repos/'.self::SUPPORT_SITE.'/pulls/(\d+)\z#', $path, $match) === 1 && str_contains($authorization, self::WORKFLOW_TOKEN)) {
                $state = $this->helpProposals[(int) $match[1]] ?? null;

                return $state === null ? Http::response(['message' => 'Not Found'], 404) : Http::response([
                    'number' => (int) $match[1],
                    'state' => $state === 'open' ? 'open' : 'closed',
                    'merged_at' => $state === 'merged' ? '2026-10-21T09:00:00Z' : null,
                    'html_url' => 'https://github.com/'.self::SUPPORT_SITE.'/pull/'.$match[1],
                ]);
            }

            // A private repository answers 404 to a key that may not read it.
            return Http::response(['message' => 'Not Found'], 404);
        }]);
    }
}
