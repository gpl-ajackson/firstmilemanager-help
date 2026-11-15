<?php

namespace App\Reports;

use Carbon\CarbonImmutable;

/**
 * A pull request merged into the main project's main, with its help line as the main project's
 * Help content check reads it (stockist .github/workflows/help-content.yml, the same patterns):
 * a link to its support-site Help Proposal, or "No help changes: <reason>", read with HTML
 * comments taken out (the pull request template's guidance is one). A link wins over "No help
 * changes". Dependabot's are exempt there, so they are here. The Release Check (#68) reads the
 * same lines (App\Releases\HelpLine, the same rules).
 */
final readonly class MergedChange
{
    public const DEPENDABOT = 'dependabot[bot]';

    public function __construct(
        public int $number,
        public string $title,
        public string $body,
        public CarbonImmutable $mergedAt,
        public string $author,
    ) {}

    /**
     * The support-site pull requests its description links: its Help Proposal (a spec's ticket
     * links the spec's shared one), as https://github.com/<support site>/pull/<n> or
     * <support site>#<n>.
     *
     * @return list<int>
     */
    public function helpProposals(string $supportSite): array
    {
        $repository = preg_quote($supportSite, '~');
        preg_match_all("~github\\.com/{$repository}/pull/([0-9]+)|{$repository}#([0-9]+)~", $this->description(), $links, PREG_SET_ORDER);

        return array_values(array_unique(array_map(fn (array $link): int => (int) ($link[1] !== '' ? $link[1] : $link[2]), $links)));
    }

    /** Whether it says "No help changes: <reason>" (any capitals), with a reason that isn't the template's "<reason>". */
    public function saysNoHelpChanges(): bool
    {
        return preg_match('/No help changes: *[^\s<]/i', $this->description()) === 1;
    }

    /** The description as it reads, without HTML comments. */
    private function description(): string
    {
        return (string) preg_replace('/<!--.*?(-->|\z)/s', '', $this->body);
    }

    public function isExempt(): bool
    {
        return $this->author === self::DEPENDABOT;
    }
}
