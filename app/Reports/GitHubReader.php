<?php

namespace App\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * What the monthly reports read from GitHub with one read-only key: the main project's merged
 * pull requests and when its issues were last edited (FMM_READ_TOKEN), and this
 * repository's pull requests (the workflow's own token). Nothing here writes; posting goes
 * through App\IssueDesk\GitHubIssues. Tests fake GitHub with Http::fake().
 */
final readonly class GitHubReader
{
    private const API = 'https://api.github.com';

    /** GitHub's largest page of search results; a search gives at most 1,000. */
    private const PAGE = 100;

    public function __construct(private string $token) {}

    /**
     * The pull requests merged into $base of $repository between two moments, oldest first.
     *
     * @return list<MergedChange>
     *
     * @throws GitHubUnreadable
     */
    public function mergedPullRequests(string $repository, string $base, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = "repo:{$repository} is:pr is:merged base:{$base} merged:{$from->toIso8601String()}..{$to->toIso8601String()}";
        $changes = [];
        for ($page = 1; ; $page++) {
            $items = $this->send(fn (PendingRequest $http) => $http->get(self::API.'/search/issues', ['q' => $query, 'per_page' => self::PAGE, 'page' => $page]))->json('items');
            $items = is_array($items) ? $items : [];
            foreach ($items as $item) {
                $pullRequest = is_array($item) ? ($item['pull_request'] ?? null) : null;
                $mergedAt = is_array($pullRequest) ? ($pullRequest['merged_at'] ?? null) : null;
                if (! is_array($item) || ! is_int($item['number'] ?? null) || ! is_string($mergedAt)) {
                    continue;
                }
                $user = $item['user'] ?? null;
                $changes[] = new MergedChange(
                    number: $item['number'],
                    title: is_string($item['title'] ?? null) ? $item['title'] : '',
                    body: is_string($item['body'] ?? null) ? $item['body'] : '',
                    mergedAt: CarbonImmutable::parse($mergedAt),
                    author: is_array($user) && is_string($user['login'] ?? null) ? $user['login'] : '',
                );
            }
            if (count($items) < self::PAGE || $page * self::PAGE >= 1000) {
                break;
            }
        }
        usort($changes, fn (MergedChange $a, MergedChange $b): int => [$a->mergedAt, $a->number] <=> [$b->mergedAt, $b->number]);

        return $changes;
    }

    /**
     * A pull request's state: 'open', 'merged' or 'closed' (closed without merging); null when
     * the repository has no such pull request.
     *
     * @throws GitHubUnreadable
     */
    public function pullRequestState(string $repository, int $number): ?string
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get(self::API."/repos/{$repository}/pulls/{$number}"), allowMissing: true);
        if ($response->status() === 404) {
            return null;
        }

        return $response->json('merged') === true ? 'merged' : ($response->json('state') === 'open' ? 'open' : 'closed');
    }

    /**
     * When each issue's (or pull request's) description was last edited after it was opened,
     * in one request; null for one never edited, and left out for a number the repository
     * hasn't got.
     *
     * @param  list<int>  $numbers
     * @return array<int, CarbonImmutable|null>
     *
     * @throws GitHubUnreadable
     */
    public function lastEdited(string $repository, array $numbers): array
    {
        if ($numbers === []) {
            return [];
        }
        [$owner, $name] = explode('/', $repository, 2);
        $fields = implode(' ', array_map(fn (int $number): string => "i{$number}: issueOrPullRequest(number: {$number}) { ... on Issue { number lastEditedAt } ... on PullRequest { number lastEditedAt } }", $numbers));
        $query = 'query { repository(owner: '.json_encode($owner).', name: '.json_encode($name).") { {$fields} } }";

        $response = $this->send(fn (PendingRequest $http) => $http->post(self::API.'/graphql', ['query' => $query]));
        $found = $response->json('data.repository');
        if (! is_array($found)) {
            $errors = $response->json('errors.0.message');
            throw new GitHubUnreadable('GitHub gave no answer'.(is_string($errors) ? " ({$errors})" : ''));
        }

        $edited = [];
        foreach ($numbers as $number) {
            $issue = $found["i{$number}"] ?? null;
            if (is_array($issue)) {
                $edited[$number] = is_string($issue['lastEditedAt'] ?? null) ? CarbonImmutable::parse($issue['lastEditedAt']) : null;
            }
        }

        return $edited;
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     *
     * @throws GitHubUnreadable
     */
    private function send(callable $request, bool $allowMissing = false): Response
    {
        $http = Http::withToken($this->token)
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(20);

        try {
            $response = $request($http);
        } catch (ConnectionException $e) {
            throw new GitHubUnreadable("GitHub can't be reached: {$e->getMessage()}");
        }
        if ($response->failed() && ! ($allowMissing && $response->status() === 404)) {
            $message = $response->json('message');
            throw new GitHubUnreadable("GitHub answered {$response->status()}".(is_string($message) ? " ({$message})" : ''));
        }

        return $response;
    }
}
