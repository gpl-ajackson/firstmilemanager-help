<?php

namespace App\Releases\Check;

use App\LaunchChecks\SourceReferences\MainProjectIssues;
use Illuminate\Support\Facades\Process;

/**
 * The main project's history as a checkout of it holds it (rule 21: Stockist is read only
 * through its repository). Its main runs straight down the first-parent line: each commit there
 * is a pull request's merge ("Merge pull request #N from ...", or a squash "Title (#N)") or a
 * commit pushed straight to main. The last FMM Release is where the demo branch is
 * (origin/demo in a checkout fetched with its whole history).
 */
final readonly class MainProjectHistory
{
    public const string REPOSITORY = MainProjectIssues::REPOSITORY;

    public function __construct(private string $checkout) {}

    /**
     * The full commit a ref names in the checkout.
     *
     * @throws InvalidRelease when the folder isn't a git checkout or has no such ref.
     */
    public function commitOf(string $ref): string
    {
        $result = Process::path($this->checkout)->run(['git', 'rev-parse', '--verify', '--quiet', "{$ref}^{commit}"]);
        if (! $result->successful()) {
            throw new InvalidRelease(is_dir($this->checkout.'/.git') || Process::path($this->checkout)->run(['git', 'rev-parse', '--git-dir'])->successful()
                ? "the main project's checkout at {$this->checkout} has no {$ref}"
                : "{$this->checkout} isn't a git checkout of the main project");
        }

        return trim($result->output());
    }

    /**
     * Each commit on main's first-parent line after $since up to $release, oldest first, with
     * the pull request its subject names (null when it names none).
     *
     * @return list<array{commit: string, pullRequest: ?int, subject: string, message: string}>
     *
     * @throws InvalidRelease
     */
    public function commitsBetween(string $since, string $release): array
    {
        $result = Process::path($this->checkout)->run(['git', 'log', '--first-parent', '--reverse', '--format=%H%x1f%B%x1e', "{$since}..{$release}"]);
        if (! $result->successful()) {
            throw new InvalidRelease("git couldn't list the main project's commits since {$since}: ".trim($result->errorOutput()));
        }

        $commits = [];
        foreach (explode("\x1e", $result->output()) as $entry) {
            $entry = ltrim($entry, "\n");
            if ($entry === '') {
                continue;
            }
            [$commit, $message] = array_pad(explode("\x1f", $entry, 2), 2, '');
            $message = rtrim($message);
            $subject = strtok($message, "\n") ?: '';
            $commits[] = ['commit' => $commit, 'pullRequest' => self::pullRequestNamedIn($subject), 'subject' => $subject, 'message' => $message];
        }

        return $commits;
    }

    /** The pull request GitHub's merge or squash commit subject names. */
    private static function pullRequestNamedIn(string $subject): ?int
    {
        if (preg_match('/\AMerge pull request #(\d+) from /', $subject, $match) === 1
            || preg_match('/\(#(\d+)\)\z/', $subject, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }
}
