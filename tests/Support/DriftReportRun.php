<?php

namespace Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * Runs the Help Drift Report (help:drift-report) as its monthly workflow does, from fixture
 * repositories: a fixture content folder (this repository), a fixture main-project checkout
 * with its git history, and GitHub faked at its boundary (spec #30, seam 4). The main project's
 * issues, their edits and its merged pull requests are served from the fixture with the
 * read-only key (FMM_READ_TOKEN); this repository's issues and pull requests (Help
 * Proposals) by FakeGitHubIssues, with the workflow's own token (GITHUB_TOKEN).
 *
 *     $run = driftReport(content()->article('x'))->against(mainProject()->commit('Start', '2026-09-01'))->run();
 *     $run->issue();   // the issue it raised: title, body, assignees, labels
 */
final class DriftReportRun
{
    public const RUN_URL = 'https://github.com/gpl-ajackson/firstmilemanager-help/actions/runs/123';

    private ?FixtureMainProject $mainProject = null;

    private bool $readsStockist = true;

    public FakeGitHubIssues $github;

    public int $exitCode = 0;

    public string $output = '';

    public function __construct(private readonly FixtureContent $content)
    {
        $this->github = FakeGitHubIssues::fresh();
    }

    public function against(FixtureMainProject $mainProject): self
    {
        $this->mainProject = $mainProject;

        return $this;
    }

    /** Runs against the GitHub an earlier run used, with the issues it raised. */
    public function onGitHub(FakeGitHubIssues $github): self
    {
        $this->github = $github;

        return $this;
    }

    /** A pull request of this repository (a Help Proposal): 'open', 'merged' or 'closed'. */
    public function helpProposal(int $number, string $state): self
    {
        $this->github->pullRequest($number, $state);

        return $this;
    }

    /** Runs without FMM_READ_TOKEN, so the main project's issues and pull requests can't be read. */
    public function withoutStockistToken(): self
    {
        $this->readsStockist = false;

        return $this;
    }

    /** @param  array<string, mixed>  $options  More command options, such as ['--month' => '2026-10']. */
    public function run(array $options = []): self
    {
        $this->content->withDefaultRelatedArticles();
        $this->mainProject ??= FixtureMainProject::make();
        if (! $this->mainProject->isCommitted()) {
            $this->mainProject->commit('The main project as it was', '2026-01-05 09:00');
        }

        config(['help.main_project_read_token' => $this->readsStockist ? 'fixture-read-token' : null]);
        $this->serveStockist($this->mainProject);
        $this->github->listen();
        // The picture tool asks its own copy of Stockist, on this machine, whether it is serving yet.
        Http::allowStrayRequests(['http://127.0.0.1:*']);
        putenv('GITHUB_TOKEN='.FakeGitHubIssues::TOKEN);

        try {
            $this->exitCode = Artisan::call('help:drift-report', [
                '--content' => $this->content->path(),
                '--pictures' => $this->content->picturesPath(),
                '--main-project' => $this->mainProject->path(),
                '--support-site-ref' => 'main',
                '--run-url' => self::RUN_URL,
                '--post-to' => FakeGitHubIssues::REPOSITORY,
                ...$options,
            ]);
        } finally {
            putenv('GITHUB_TOKEN');
        }
        $this->output = Artisan::output();

        return $this;
    }

    /**
     * The one issue the report raised.
     *
     * @return array{number: int, title: string, body: string, assignees: list<string>, labels: list<string>, state: string, comments: list<string>}
     */
    public function issue(): array
    {
        $issues = $this->github->issues();
        if (count($issues) !== 1) {
            throw new \RuntimeException('Expected one issue, GitHub has '.count($issues).". The run said:\n".$this->output);
        }

        return $issues[0];
    }

    /**
     * The items of one section of the issue, by its heading's first words, as the Content Owner
     * reads them: each "- [ ] ..." line with its checkbox taken off; ['None this month.'] for an
     * empty one, or its "Not checked" line.
     *
     * @return list<string>
     */
    public function section(string $heading): array
    {
        $body = $this->issue()['body'];
        $start = null;
        $lines = explode("\n", $body);
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, '## '.$heading)) {
                $start = $index + 1;
                break;
            }
        }
        if ($start === null) {
            throw new \RuntimeException("The issue has no section \"{$heading}\":\n{$body}");
        }

        $items = [];
        foreach (array_slice($lines, $start) as $line) {
            if (str_starts_with($line, '## ') || str_starts_with($line, '<sub>')) {
                break;
            }
            if (trim($line) !== '') {
                $items[] = str_starts_with($line, '- [ ] ') ? substr($line, 6) : $line;
            }
        }

        return $items;
    }

    /**
     * GitHub for gpl-ajackson/firstmilemanager, from the fixture: its issues (REST, as Launch Check 2
     * reads them), when their descriptions were last edited (GraphQL) and its merged pull
     * requests (the search API, filtered by the merged: range asked for), each answering only
     * the read-only key.
     */
    private function serveStockist(FixtureMainProject $mainProject): void
    {
        $readOnly = fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer fixture-read-token');

        Http::fake([
            'api.github.com/repos/gpl-ajackson/firstmilemanager/issues/*' => function (Request $request) use ($mainProject, $readOnly) {
                $issue = $mainProject->issues()[(int) basename((string) parse_url($request->url(), PHP_URL_PATH))] ?? null;

                return ! $readOnly($request) ? Http::response(['message' => 'Bad credentials'], 401)
                    : ($issue === null ? Http::response(['message' => 'Not Found'], 404) : Http::response($issue));
            },
            'api.github.com/graphql' => function (Request $request) use ($mainProject, $readOnly) {
                if (! $readOnly($request)) {
                    return Http::response(['message' => 'Bad credentials'], 401);
                }
                preg_match_all('/(i\d+): issueOrPullRequest\(number: (\d+)\)/', (string) $request['query'], $asked, PREG_SET_ORDER);
                $repository = [];
                $errors = [];
                foreach ($asked as [, $alias, $number]) {
                    $issue = $mainProject->issues()[(int) $number] ?? null;
                    $repository[$alias] = $issue === null ? null : [
                        'number' => (int) $number,
                        'lastEditedAt' => $mainProject->edits()[(int) $number] ?? null,
                    ];
                    if ($issue === null) {
                        $errors[] = ['type' => 'NOT_FOUND', 'path' => ['repository', $alias], 'message' => "Could not resolve to an issue or pull request with the number of {$number}."];
                    }
                }

                return Http::response(['data' => ['repository' => $repository], ...($errors === [] ? [] : ['errors' => $errors])]);
            },
            'api.github.com/search/issues*' => function (Request $request) use ($mainProject, $readOnly) {
                if (! $readOnly($request)) {
                    return Http::response(['message' => 'Bad credentials'], 401);
                }
                $query = (string) ($request->data()['q'] ?? '');
                if (preg_match('/merged:(\S+)\.\.(\S+)/', $query, $range) !== 1 || ! str_contains($query, 'repo:gpl-ajackson/firstmilemanager ')
                    || ! str_contains($query, 'is:pr') || ! str_contains($query, 'is:merged') || ! str_contains($query, 'base:main')) {
                    return Http::response(['message' => 'Validation Failed', 'query' => $query], 422);
                }
                [$from, $to] = [CarbonImmutable::parse($range[1]), CarbonImmutable::parse($range[2])];
                $items = [];
                foreach ($mainProject->mergedPullRequests() as $pull) {
                    $merged = CarbonImmutable::parse($pull['merged_at'], 'Europe/London');
                    if ($merged->betweenIncluded($from, $to)) {
                        $items[] = [
                            'number' => $pull['number'],
                            'title' => $pull['title'],
                            'body' => $pull['body'],
                            'html_url' => "https://github.com/gpl-ajackson/firstmilemanager/pull/{$pull['number']}",
                            'user' => ['login' => $pull['author']],
                            'pull_request' => ['merged_at' => $merged->utc()->format('Y-m-d\TH:i:s\Z')],
                        ];
                    }
                }

                return Http::response(['total_count' => count($items), 'incomplete_results' => false, 'items' => $items]);
            },
        ]);
    }
}
