<?php

namespace App\LaunchChecks\HelpOnly;

/**
 * A support-site pull request's description, as the Help only check and CI read it: the
 * main-project pull request it links (its paired change), or its "Help only: <reason>" line.
 * HTML comments (a pull request template's instructions) are ignored.
 */
final readonly class PullRequestDescription
{
    public const MAIN_PROJECT = 'gpl-ajackson/firstmilemanager';

    private string $text;

    public function __construct(string $description)
    {
        $this->text = (string) preg_replace('/<!--.*?-->/s', '', $description);
    }

    /** The number of the main-project pull request the description links, if it links one. */
    public function pairedPullRequest(): ?int
    {
        $pattern = '~https://github\.com/'.preg_quote(self::MAIN_PROJECT, '~').'/pull/(\d+)\b~i';

        return preg_match($pattern, $this->text, $match) === 1 ? (int) $match[1] : null;
    }

    /** The reason on a "Help only: <reason>" line, if there is one with a reason. */
    public function helpOnlyReason(): ?string
    {
        if (preg_match('/^[\s>*`-]*Help only:[ \t]*([^\n`]*[^\s`])/mi', $this->text, $match) !== 1) {
            return null;
        }

        return trim($match[1]);
    }

    /** The main project's ref the Launch Checks read: the paired change's head, or main. */
    public function mainProjectRef(): string
    {
        $paired = $this->pairedPullRequest();

        return $paired === null ? 'main' : "refs/pull/{$paired}/head";
    }
}
