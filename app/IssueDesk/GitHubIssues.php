<?php

namespace App\IssueDesk;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Issues in one GitHub repository, opened and commented on through one key (spec #30, seam 4:
 * GitHub issues sit behind one boundary, faked in tests). The Glossary Check's weekly run
 * posts with the workflow's own token; the issue desk on the server (#48) uses the issue-only
 * key, HELP_GITHUB_ISSUES_TOKEN, through the same class.
 */
final readonly class GitHubIssues
{
    private const API = 'https://api.github.com';

    /** GitHub's largest page of a list. */
    private const PAGE = 100;

    /** @param  string  $repository  owner/name */
    public function __construct(private string $repository, private string $token) {}

    public function repository(): string
    {
        return $this->repository;
    }

    /**
     * The open issue whose body starts with a marker (a hidden HTML comment), so a job that
     * keeps one issue finds it again. Pull requests are left out.
     *
     * @throws IssueDeskUnavailable
     */
    public function openIssueMarked(string $marker): ?int
    {
        for ($page = 1; ; $page++) {
            $issues = $this->send(fn (PendingRequest $http) => $http->get($this->url('issues'), ['state' => 'open', 'per_page' => self::PAGE, 'page' => $page]))->json();
            $issues = is_array($issues) ? $issues : [];
            foreach ($issues as $issue) {
                if (is_array($issue) && ! isset($issue['pull_request']) && is_int($issue['number'] ?? null)
                    && str_starts_with(is_string($issue['body'] ?? null) ? ltrim($issue['body']) : '', $marker)) {
                    return $issue['number'];
                }
            }
            if (count($issues) < self::PAGE) {
                return null;
            }
        }
    }

    /**
     * The issue, open or closed, that carries a label and whose body starts with a marker, so a
     * job that raises one issue per period (the monthly reports) finds that period's again
     * however it was left. Pull requests are left out.
     *
     * @throws IssueDeskUnavailable
     */
    public function issueMarked(string $marker, string $label): ?int
    {
        for ($page = 1; ; $page++) {
            $issues = $this->send(fn (PendingRequest $http) => $http->get($this->url('issues'), ['state' => 'all', 'labels' => $label, 'per_page' => self::PAGE, 'page' => $page]))->json();
            $issues = is_array($issues) ? $issues : [];
            foreach ($issues as $issue) {
                if (is_array($issue) && ! isset($issue['pull_request']) && is_int($issue['number'] ?? null)
                    && str_starts_with(is_string($issue['body'] ?? null) ? ltrim($issue['body']) : '', $marker)) {
                    return $issue['number'];
                }
            }
            if (count($issues) < self::PAGE) {
                return null;
            }
        }
    }

    /**
     * Every issue, open or closed, whose body starts with a marker prefix and that was opened
     * before a moment, so a job can delete what has been kept long enough. Pull requests are
     * left out.
     *
     * @return list<array{number: int, id: string}> each issue's number and GraphQL node id
     *
     * @throws IssueDeskUnavailable
     */
    public function issuesMarkedOpenedBefore(string $markerPrefix, CarbonInterface $before): array
    {
        $found = [];
        for ($page = 1; ; $page++) {
            $issues = $this->send(fn (PendingRequest $http) => $http->get($this->url('issues'), ['state' => 'all', 'per_page' => self::PAGE, 'page' => $page]))->json();
            $issues = is_array($issues) ? $issues : [];
            foreach ($issues as $issue) {
                if (is_array($issue) && ! isset($issue['pull_request']) && is_int($issue['number'] ?? null) && is_string($issue['node_id'] ?? null)
                    && is_string($issue['created_at'] ?? null) && CarbonImmutable::parse($issue['created_at'])->lessThan($before)
                    && str_starts_with(is_string($issue['body'] ?? null) ? ltrim($issue['body']) : '', $markerPrefix)) {
                    $found[] = ['number' => $issue['number'], 'id' => $issue['node_id']];
                }
            }
            if (count($issues) < self::PAGE) {
                return $found;
            }
        }
    }

    /**
     * Deletes an issue for good, with its comments, through GitHub's GraphQL API (the REST API
     * can only close one). GitHub lets only a key whose owner administers the repository do it.
     *
     * @param  string  $id  the issue's GraphQL node id
     *
     * @throws IssueDeskUnavailable
     */
    public function delete(string $id): void
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post(self::API.'/graphql', [
            'query' => 'mutation($id: ID!) { deleteIssue(input: {issueId: $id}) { clientMutationId } }',
            'variables' => ['id' => $id],
        ]));
        $errors = $response->json('errors');
        if (is_array($errors) && $errors !== []) {
            $message = is_array($errors[0] ?? null) && is_string($errors[0]['message'] ?? null) ? $errors[0]['message'] : 'no reason given';
            throw new IssueDeskUnavailable("GitHub wouldn't delete the issue ({$message})");
        }
    }

    /**
     * Opens an issue and gives its address.
     *
     * @param  list<string>  $assignees  GitHub logins
     * @param  list<string>  $labels  label names; GitHub makes a label the repository hasn't got yet
     *
     * @throws IssueDeskUnavailable
     */
    public function open(string $title, string $body, array $assignees = [], array $labels = []): string
    {
        return $this->openIssue($title, $body, $assignees, $labels)->address;
    }

    /**
     * Opens an issue and gives its number and address, for a caller that comments on it next.
     *
     * @param  list<string>  $assignees  GitHub logins
     * @param  list<string>  $labels  label names; GitHub makes a label the repository hasn't got yet
     *
     * @throws IssueDeskUnavailable
     */
    public function openIssue(string $title, string $body, array $assignees = [], array $labels = []): OpenedIssue
    {
        $issue = ['title' => $title, 'body' => $body, 'assignees' => $assignees];
        if ($labels !== []) {
            $issue['labels'] = $labels;
        }
        $response = $this->send(fn (PendingRequest $http) => $http->post($this->url('issues'), $issue));
        $number = $response->json('number');
        if (! is_int($number)) {
            throw new IssueDeskUnavailable('GitHub gave no number for the issue it opened');
        }

        return new OpenedIssue($number, self::address($response));
    }

    /**
     * Adds a comment to an issue and gives the comment's address.
     *
     * @throws IssueDeskUnavailable
     */
    public function comment(int $issue, string $body): string
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post($this->url("issues/{$issue}/comments"), ['body' => $body]));

        return self::address($response);
    }

    /** The address GitHub gives what was made, for the log. */
    private static function address(Response $response): string
    {
        $address = $response->json('html_url');

        return is_string($address) ? $address : '(GitHub gave no address)';
    }

    private function url(string $path): string
    {
        return self::API.'/repos/'.$this->repository.'/'.$path;
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     *
     * @throws IssueDeskUnavailable
     */
    private function send(callable $request): Response
    {
        $http = Http::withToken($this->token)
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(20);

        try {
            $response = $request($http);
        } catch (ConnectionException $e) {
            throw new IssueDeskUnavailable("GitHub can't be reached: {$e->getMessage()}");
        }
        if ($response->failed()) {
            $message = $response->json('message');
            throw new IssueDeskUnavailable("GitHub answered {$response->status()}".(is_string($message) ? " ({$message})" : ''));
        }

        return $response;
    }
}
