<?php

namespace App\Releases\Check;

/**
 * What a main-project pull request's description (or a commit pushed straight to main) says about
 * its help, read exactly as the main project's Help content check reads it
 * (gpl-ajackson/firstmilemanager .github/workflows/help-content.yml, docs/agents/help-content.md): a link
 * to a support-site pull request (the Help Proposal), or "No help changes: <reason>". HTML
 * comments are left out first, so the pull request template's own comment, which names both,
 * says nothing. A link wins over "No help changes", since it promises help that must merge.
 */
final readonly class HelpLine
{
    public const string SUPPORT_SITE = 'gpl-ajackson/firstmilemanager-help';

    /**
     * @param  list<int>  $helpProposals  the support-site pull requests it links, in the order written
     * @param  string|null  $noHelpChanges  the reason after "No help changes:", when it links none
     */
    private function __construct(
        public array $helpProposals,
        public ?string $noHelpChanges,
    ) {}

    public static function of(string $description): self
    {
        $text = (string) preg_replace('/<!--.*?(-->|\z)/s', '', $description);

        $repository = preg_quote(self::SUPPORT_SITE, '~');
        preg_match_all("~(?:github\\.com/{$repository}/pull/|{$repository}#)(\\d+)~i", $text, $matches);
        $proposals = array_values(array_unique(array_map('intval', $matches[1])));
        if ($proposals !== []) {
            return new self($proposals, null);
        }

        return new self([], preg_match('/No help changes: *([^\s<][^\r\n]*)/i', $text, $match) === 1 ? rtrim($match[1]) : null);
    }
}
