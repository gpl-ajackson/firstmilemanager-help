<?php

namespace App\LaunchChecks\Glossary;

use App\LaunchChecks\Findings;
use App\LaunchChecks\MarkdownReport;
use Carbon\CarbonImmutable;

/**
 * The Glossary Check's weekly findings as the Content Owner reads them on GitHub: one issue
 * (found again by its marker) holding a comment per week with something to report.
 */
final class WeeklyGlossaryReport
{
    /** The hidden first line of the issue's body, by which each week's run finds it again. */
    public const MARKER = '<!-- help-glossary-check -->';

    public const TITLE = 'Glossary Check: what GLOSSARY.md changed';

    /** The issue's body: what the issue is for, then the first week's findings. */
    public static function issueBody(Findings $findings, string $mainProjectRef, CarbonImmutable $day): string
    {
        return self::MARKER."\n"
            ."The Glossary Check runs every week against the main project's `main` and adds what it finds here, so the help centre's Glossary keeps up with `GLOSSARY.md` (rule 20). Work each item in a Help Proposal, or note why no change is needed, then close this issue: the next week with findings opens a new one.\n\n"
            .self::comment($findings, $mainProjectRef, $day);
    }

    /** One week's findings: failures first, then the reports, which never fail. */
    public static function comment(Findings $findings, string $mainProjectRef, CarbonImmutable $day): string
    {
        $lines = ['## Glossary Check, '.$day->format('j F Y'), ''];
        $lines[] = "Against the main project at `{$mainProjectRef}`.";
        $lines[] = '';

        if ($findings->failures() !== []) {
            $lines[] = '### Fails: a published entry names a term `GLOSSARY.md` no longer has';
            $lines[] = '';
            foreach ($findings->failures() as $finding) {
                $lines[] = MarkdownReport::line($finding);
            }
            $lines[] = '';
        }
        if ($findings->reports() !== []) {
            $lines[] = '### To look at (never failing)';
            $lines[] = '';
            foreach ($findings->reports() as $finding) {
                $lines[] = MarkdownReport::line($finding);
            }
            $lines[] = '';
        }
        $lines[] = '<sub>Run it locally: `php artisan help:glossary-check --main-project=<path to a firstmilemanager checkout> --stockist=<path to a stockist checkout>`.</sub>';

        return implode("\n", $lines)."\n";
    }
}
