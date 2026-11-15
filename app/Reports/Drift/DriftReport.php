<?php

namespace App\Reports\Drift;

use App\Reports\HelpDriftReport;
use App\Reports\ReportMonth;
use Carbon\CarbonImmutable;

/** One month's Help Drift Report, as the Content Owner reads it on GitHub. */
final readonly class DriftReport
{
    /** GitHub's limit on an issue's body is 65,536 characters; a long report is cut well before it. */
    private const MAX_LENGTH = 60000;

    /** @param  list<DriftSection>  $sections */
    public function __construct(
        public ReportMonth $month,
        public CarbonImmutable $day,
        public array $sections,
        public DriftLinks $links,
        public string $mainProjectRef,
    ) {}

    public function title(): string
    {
        return HelpDriftReport::title($this->month);
    }

    /** Whether every section was checked in full. */
    public function checkedEverything(): bool
    {
        foreach ($this->sections as $section) {
            if ($section->notChecked !== []) {
                return false;
            }
        }

        return true;
    }

    /** The issue's body: its marker, what it is, then each section. */
    public function body(): string
    {
        $mainProject = "[`{$this->short($this->links->mainProjectCommit)}`]({$this->links->mainProjectCommit($this->links->mainProjectCommit)})";
        $lines = [
            HelpDriftReport::marker($this->month),
            "Where help may have fallen behind First Mile Manager in {$this->month->name()}, checked on {$this->day->format('j F Y')} against the main project's `{$this->mainProjectRef}` at {$mainProject}.",
            '',
            'Work each item in a Help Proposal, or tick it and say in a comment why no change is needed (plan S7 step 9). What this report finds feeds review and retirement (S8).',
            '',
            ...$this->sectionLines(),
            '<sub>Raised by the Help Drift Report workflow'.($this->links->runUrl === null ? '' : " ([this run]({$this->links->runUrl}))")
                .'. Run it locally: `php artisan help:drift-report --main-project=<path to a stockist checkout with its history>`.</sub>',
        ];
        $markdown = implode("\n", $lines)."\n";

        return strlen($markdown) > self::MAX_LENGTH
            ? substr($markdown, 0, self::MAX_LENGTH)."\n\n_The report is cut here; the workflow's log has every item._\n"
            : $markdown;
    }

    /** @return list<string> */
    private function sectionLines(): array
    {
        $lines = [];
        foreach ($this->sections as $section) {
            $lines[] = "## {$section->heading}".($section->items === [] ? '' : ' ('.count($section->items).')');
            $lines[] = '';
            foreach ($section->items as $item) {
                $lines[] = "- [ ] {$item}";
            }
            if ($section->items === [] && $section->notChecked === []) {
                $lines[] = 'None this month.';
            }
            foreach ($section->notChecked as $why) {
                $lines[] = "_Not checked:_ {$why}.";
            }
            $lines[] = '';
        }

        return $lines;
    }

    private function short(string $commit): string
    {
        return preg_match('/\A[0-9a-f]{40}\z/', $commit) === 1 ? substr($commit, 0, 12) : $commit;
    }
}
