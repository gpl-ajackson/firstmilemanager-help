<?php

namespace Tests\Support;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The faked GitHub issue desk (spec #30, seam 4): GitHub's issues API for one repository, kept in
 * memory, so a test can see the issues and comments the help centre made and close an issue as
 * its owner would. Every other request to GitHub, and any request elsewhere, fails the test.
 *
 *     $github = FakeGitHubIssues::start();
 *     ... run the issue desk ...
 *     $github->issues();     // each issue: number, title, body, assignees, labels, state, comments, created_at
 *     $github->deleted();    // the numbers of the issues deleted through GraphQL's deleteIssue
 *     $github->refusesDeletes(); // deleteIssue answers as GitHub does for a key that can't delete
 *     $github->pullRequest(77, 'open'); // a pull request of this repository (a Help Proposal)
 *     $github->raised($title, $body, [$label]); // an issue the Help Drift Report's workflow raised
 *     $github->close(1);
 *     $github->unreachable(); // GitHub can't be reached until reachable() is called
 */
final class FakeGitHubIssues
{
    public const REPOSITORY = 'gpl-ajackson/firstmilemanager-help';

    public const TOKEN = 'fixture-issues-token';

    /** @var array<int, array{number: int, title: string, body: string, assignees: list<string>, labels: list<string>, state: string, comments: list<string>, created_at: string}> */
    private array $issues = [];

    /** @var array<int, array{state: string, merged: bool}> this repository's pull requests */
    private array $pullRequests = [];

    private bool $reachable = true;

    /** @var list<int> the issues deleted, in the order they went */
    private array $deleted = [];

    private bool $refusesDeletes = false;

    /** The last issue number given; GitHub never gives a deleted issue's number again. */
    private int $lastNumber = 0;

    /** Whether it answers GitHub's API yet: once only, since every fake that matches is asked. */
    private bool $listening = false;

    /** How many requests reached GitHub (not counting the ones made while it was unreachable). */
    public int $requests = 0;

    public static function start(): self
    {
        return self::fresh()->listen();
    }

    /** A fake not answering yet, so a test can make its own fakes of GitHub first (they answer first). */
    public static function fresh(): self
    {
        return new self;
    }

    /** Starts answering GitHub's API; every request no earlier fake answered comes here. */
    public function listen(): self
    {
        if ($this->listening) {
            return $this;
        }
        $this->listening = true;
        config(['help.issue_desk.repository' => self::REPOSITORY, 'help.issue_desk.token' => self::TOKEN]);
        Http::preventStrayRequests();
        Http::fake(['api.github.com/*' => fn (Request $request) => $this->answer($request)]);

        return $this;
    }

    /**
     * Every issue, oldest first.
     *
     * @return list<array{number: int, title: string, body: string, assignees: list<string>, labels: list<string>, state: string, comments: list<string>, created_at: string}>
     */
    public function issues(): array
    {
        return array_values($this->issues);
    }

    /** @return list<int> the numbers of the issues deleted, in the order they went */
    public function deleted(): array
    {
        return $this->deleted;
    }

    /** From now on deleteIssue is refused (or, given false, allowed again), as GitHub refuses a key whose owner can't administer the repository. */
    public function refusesDeletes(bool $refuses = true): self
    {
        $this->refusesDeletes = $refuses;

        return $this;
    }

    /**
     * An issue raised by something other than the code under test, such as the Help Drift
     * Report's workflow; it takes the next number, as on GitHub.
     *
     * @param  list<string>  $labels
     */
    public function raised(string $title, string $body, array $labels = []): self
    {
        $number = ++$this->lastNumber;
        $this->issues[$number] = ['number' => $number, 'title' => $title, 'body' => $body, 'assignees' => [], 'labels' => $labels, 'state' => 'open', 'comments' => [], 'created_at' => self::now()];

        return $this;
    }

    /** The owner closes an issue. */
    public function close(int $number): self
    {
        $this->issues[$number]['state'] = 'closed';

        return $this;
    }

    /**
     * A pull request of this repository, such as a Help Proposal: 'open', 'merged', or 'closed'
     * (closed without merging).
     */
    public function pullRequest(int $number, string $state): self
    {
        $this->pullRequests[$number] = ['state' => $state === 'open' ? 'open' : 'closed', 'merged' => $state === 'merged'];

        return $this;
    }

    public function unreachable(): self
    {
        $this->reachable = false;

        return $this;
    }

    public function reachable(): self
    {
        $this->reachable = true;

        return $this;
    }

    private function answer(Request $request): mixed
    {
        if (! $this->reachable) {
            return (Factory::failedConnection())($request);
        }
        $this->requests++;
        if (! $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)) {
            return Http::response(['message' => 'Bad credentials'], 401);
        }

        $base = 'https://api.github.com/repos/'.self::REPOSITORY.'/issues';
        $path = strtok($request->url(), '?');
        $method = $request->method();

        if ($method === 'GET' && $path === $base) {
            return $this->list($request);
        }
        if ($method === 'POST' && $path === $base) {
            return $this->open($request);
        }
        if ($method === 'POST' && $path === 'https://api.github.com/graphql') {
            return $this->graphql($request);
        }
        if ($method === 'POST' && preg_match('#^'.preg_quote($base, '#').'/(\d+)/comments$#', (string) $path, $match) === 1) {
            return $this->comment((int) $match[1], $request);
        }
        if ($method === 'GET' && preg_match('#^https://api\.github\.com/repos/'.preg_quote(self::REPOSITORY, '#').'/pulls/(\d+)$#', (string) $path, $match) === 1) {
            $pull = $this->pullRequests[(int) $match[1]] ?? null;

            return $pull === null ? Http::response(['message' => 'Not Found'], 404) : Http::response(['number' => (int) $match[1], ...$pull]);
        }

        return Http::response(['message' => 'Not Found'], 404);
    }

    private function list(Request $request): mixed
    {
        $data = $request->data();
        $state = is_string($data['state'] ?? null) ? $data['state'] : 'open';
        $perPage = is_numeric($data['per_page'] ?? null) ? (int) $data['per_page'] : 30;
        $page = is_numeric($data['page'] ?? null) ? (int) $data['page'] : 1;
        $labels = is_string($data['labels'] ?? null) && $data['labels'] !== '' ? explode(',', $data['labels']) : [];
        // GitHub lists the newest first.
        $issues = array_values(array_filter(array_reverse($this->issues), fn (array $issue): bool => ($state === 'all' || $issue['state'] === $state)
            && array_diff($labels, $issue['labels']) === []));

        return Http::response(array_map(fn (array $issue): array => [
            'number' => $issue['number'],
            'title' => $issue['title'],
            'body' => $issue['body'],
            'state' => $issue['state'],
            'node_id' => self::nodeId($issue['number']),
            'created_at' => $issue['created_at'],
            'labels' => array_map(fn (string $name): array => ['name' => $name], $issue['labels']),
            'assignees' => array_map(fn (string $login): array => ['login' => $login], $issue['assignees']),
        ], array_slice($issues, ($page - 1) * $perPage, $perPage)));
    }

    private function open(Request $request): mixed
    {
        $number = ++$this->lastNumber;
        $assignees = $request['assignees'] ?? [];
        $labels = $request['labels'] ?? [];
        $this->issues[$number] = [
            'number' => $number,
            'title' => (string) $request['title'],
            'body' => (string) $request['body'],
            'assignees' => is_array($assignees) ? array_values(array_map('strval', $assignees)) : [],
            'labels' => is_array($labels) ? array_values(array_map('strval', $labels)) : [],
            'state' => 'open',
            'comments' => [],
            'created_at' => self::now(),
        ];

        return Http::response(['number' => $number, 'html_url' => 'https://github.com/'.self::REPOSITORY."/issues/{$number}"], 201);
    }

    private function comment(int $number, Request $request): mixed
    {
        if (! isset($this->issues[$number])) {
            return Http::response(['message' => 'Not Found'], 404);
        }
        $this->issues[$number]['comments'][] = (string) $request['body'];
        $comment = count($this->issues[$number]['comments']);

        return Http::response(['html_url' => 'https://github.com/'.self::REPOSITORY."/issues/{$number}#issuecomment-{$comment}"], 201);
    }

    /** GitHub's GraphQL API: only the deleteIssue mutation is answered. */
    private function graphql(Request $request): mixed
    {
        $query = is_string($request['query'] ?? null) ? $request['query'] : '';
        $variables = is_array($request['variables'] ?? null) ? $request['variables'] : [];
        $id = is_string($variables['id'] ?? null) ? $variables['id'] : '';
        if (! str_contains($query, 'deleteIssue')) {
            return Http::response(['errors' => [['message' => 'Only deleteIssue is faked']]]);
        }
        if ($this->refusesDeletes) {
            return Http::response(['data' => ['deleteIssue' => null], 'errors' => [['type' => 'FORBIDDEN', 'message' => 'fixture-user does not have the correct permissions to execute `DeleteIssue`']]]);
        }
        $number = preg_match('/^I_fixture(\d+)$/', $id, $match) === 1 ? (int) $match[1] : 0;
        if (! isset($this->issues[$number])) {
            return Http::response(['data' => ['deleteIssue' => null], 'errors' => [['type' => 'NOT_FOUND', 'message' => "Could not resolve to a node with the global id of '{$id}'"]]]);
        }
        unset($this->issues[$number]);
        $this->deleted[] = $number;

        return Http::response(['data' => ['deleteIssue' => ['clientMutationId' => null]]]);
    }

    private static function nodeId(int $number): string
    {
        return "I_fixture{$number}";
    }

    /** When GitHub opens an issue: now, as the test's clock says, in UTC as GitHub writes it. */
    private static function now(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
