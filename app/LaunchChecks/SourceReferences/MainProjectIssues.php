<?php

namespace App\LaunchChecks\SourceReferences;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The main project's issues on GitHub (gpl-ajackson/firstmilemanager), which Source References cite as
 * fmm#n. Specs live there, not in a checkout, so check 2 reads an issue's body as it is now
 * through GitHub's REST API (GET /repos/{owner}/{repo}/issues/{n}), with the read-only key CI
 * holds as FMM_READ_TOKEN (config help.main_project_read_token). A paired main-project change
 * edits spec bodies directly, so "now" is the ref under test for an issue. Tests fake GitHub
 * with Http::fake() (LaunchCheckRun serves the fixture main project's issues).
 */
final class MainProjectIssues
{
    public const REPOSITORY = 'gpl-ajackson/firstmilemanager';

    /** @var array<int, MainProjectIssue|null> Each issue read once per run; null for one GitHub doesn't have. */
    private array $read = [];

    public function __construct(private readonly string $token) {}

    /**
     * The issue, or null when GitHub says there is no such issue.
     *
     * @throws UnreadableIssue when GitHub can't be asked or doesn't answer.
     */
    public function find(int $number): ?MainProjectIssue
    {
        if (array_key_exists($number, $this->read)) {
            return $this->read[$number];
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->withToken($this->token)
                ->timeout(10)
                ->get('https://api.github.com/repos/'.self::REPOSITORY.'/issues/'.$number);
        } catch (ConnectionException $exception) {
            throw new UnreadableIssue("couldn't reach GitHub ({$exception->getMessage()})");
        }

        if ($response->status() === 404 || $response->status() === 410) {
            return $this->read[$number] = null;
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new UnreadableIssue("GitHub answered {$response->status()}");
        }

        // An issue written with no description has a null body.
        $title = $response->json('title');
        $body = $response->json('body');

        return $this->read[$number] = new MainProjectIssue(
            number: $number,
            title: is_string($title) ? $title : '',
            body: is_string($body) ? $body : '',
            closedAsCompleted: $response->json('state') === 'closed' && $response->json('state_reason') === 'completed',
            pullRequest: $response->json('pull_request') !== null,
        );
    }
}
