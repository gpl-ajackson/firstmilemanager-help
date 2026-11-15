<?php

namespace App\Releases\Check;

/**
 * One change on the main project's main since the last FMM Release: a merged pull request,
 * or a commit pushed straight to main (no pull request).
 */
final readonly class ReleasedChange
{
    public function __construct(
        /** The commit on main's first-parent line that brought it in. */
        public string $commit,
        /** The pull request's number; null for a commit pushed straight to main. */
        public ?int $pullRequest,
        /** The pull request's title, or the commit's subject. */
        public string $title,
        /** The pull request's description, or the whole commit message. */
        public string $description,
        /** The pull request's author's GitHub login; null for a commit. */
        public ?string $author = null,
    ) {}

    /** "#275", or the commit's first 12 characters. */
    public function name(): string
    {
        return $this->pullRequest === null ? substr($this->commit, 0, 12) : "#{$this->pullRequest}";
    }

    public function address(): string
    {
        return 'https://github.com/'.MainProjectHistory::REPOSITORY.($this->pullRequest === null ? "/commit/{$this->commit}" : "/pull/{$this->pullRequest}");
    }
}
