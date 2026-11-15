<?php

namespace App\Releases\Check;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The pull requests the Release Check reads on GitHub's REST API: the main project's
 * (gpl-ajackson/firstmilemanager, private) with the read-only key FMM_READ_TOKEN, and the support
 * site's Help Proposals with the workflow's own GITHUB_TOKEN. Nothing is written. Tests fake
 * GitHub with Http::fake() (tests/Support/ReleaseCheckRun.php).
 */
final readonly class PullRequests
{
    public function __construct(
        private ?string $stockistToken,
        private ?string $supportSiteToken,
    ) {}

    public function readsStockist(): bool
    {
        return $this->stockistToken !== null && $this->stockistToken !== '';
    }

    /**
     * A merged main-project pull request as a released change; null when GitHub has none.
     *
     * @throws GitHubUnreadable
     */
    public function mainProjectPullRequest(int $number, string $commit): ?ReleasedChange
    {
        $response = $this->get(MainProjectHistory::REPOSITORY."/pulls/{$number}", $this->stockistToken);
        if ($response === null) {
            return null;
        }

        $title = $response->json('title');
        $body = $response->json('body');
        $author = $response->json('user.login');

        return new ReleasedChange(
            commit: $commit,
            pullRequest: $number,
            title: is_string($title) ? $title : '',
            // A pull request written with no description has a null body.
            description: is_string($body) ? $body : '',
            author: is_string($author) ? $author : null,
        );
    }

    /**
     * The merged pull request a commit on main came from, for a merge whose subject names none
     * (a rebase merge); null when it came from none (pushed straight to main).
     *
     * @throws GitHubUnreadable
     */
    public function mainProjectPullRequestOf(string $commit): ?int
    {
        $response = $this->get(MainProjectHistory::REPOSITORY."/commits/{$commit}/pulls", $this->stockistToken);
        foreach ((array) $response?->json() as $pull) {
            if (is_array($pull) && is_int($pull['number'] ?? null) && is_string($pull['merged_at'] ?? null)) {
                return $pull['number'];
            }
        }

        return null;
    }

    /** @throws GitHubUnreadable */
    public function helpProposal(int $number): HelpProposal
    {
        $response = $this->get(HelpLine::SUPPORT_SITE."/pulls/{$number}", $this->supportSiteToken);

        return new HelpProposal($number, match (true) {
            $response === null => HelpProposal::NOT_FOUND,
            is_string($response->json('merged_at')) => HelpProposal::MERGED,
            $response->json('state') === 'open' => HelpProposal::OPEN,
            default => HelpProposal::CLOSED,
        });
    }

    /**
     * GET /repos/<path>; null on 404.
     *
     * @throws GitHubUnreadable
     */
    private function get(string $path, ?string $token): ?Response
    {
        $request = Http::acceptJson()->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])->timeout(20);
        if ($token !== null && $token !== '') {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->get("https://api.github.com/repos/{$path}");
        } catch (ConnectionException $exception) {
            throw new GitHubUnreadable("couldn't reach GitHub ({$exception->getMessage()})");
        }
        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new GitHubUnreadable("GitHub answered {$response->status()} for {$path}");
        }

        return $response;
    }
}
