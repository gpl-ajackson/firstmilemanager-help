<?php

namespace App\Reports\Gaps;

use App\Reports\HelpGapsReport;
use App\Reports\ReportMonth;
use Carbon\CarbonImmutable;

/** One month's Help Gaps Report, as the Content Owner reads it on GitHub. */
final readonly class GapsReport
{
    /** GitHub's limit on an issue's body is 65,536 characters; a long report is cut well before it. */
    private const MAX_LENGTH = 60000;

    /**
     * @param  list<string>  $opening  the marker, then what the report is, as lines of Markdown
     * @param  array<string, string>  $sections  each section's heading and Markdown, in order
     */
    public function __construct(
        public ReportMonth $month,
        public CarbonImmutable $day,
        public array $opening,
        public array $sections,
    ) {}

    public function title(): string
    {
        return HelpGapsReport::title($this->month);
    }

    /** The issue's body: its marker, what it is, then each section. */
    public function body(): string
    {
        $lines = $this->opening;
        foreach ($this->sections as $heading => $markdown) {
            array_push($lines, '', "## {$heading}", '', $markdown);
        }
        $lines[] = '';
        $lines[] = '<sub>Raised by the help centre\'s server on the first working day of the month, from its records, which hold nothing about readers. '
            ."Print it again: `php artisan help:gaps-report --month={$this->month->key()} --print`.</sub>";
        $markdown = implode("\n", $lines)."\n";

        return strlen($markdown) > self::MAX_LENGTH
            ? substr($markdown, 0, self::MAX_LENGTH)."\n\n_The report is cut here; `php artisan help:gaps-report --month={$this->month->key()} --print` on the server prints it all._\n"
            : $markdown;
    }
}
