<?php

namespace App\LaunchChecks;

/**
 * The Launch Checks' findings as the comment CI posts on the pull request: failures to fix
 * first, then the reports for the reviewer, which never fail the build.
 */
final class MarkdownReport
{
    /** The hidden line CI finds its own comment by, so each run updates it in place. */
    public const MARKER = '<!-- help-launch-checks -->';

    /** The same for the Picture Check's own comment (--pictures-only), which CI runs in a job of its own. */
    public const PICTURES_MARKER = '<!-- help-launch-checks-pictures -->';

    /** GitHub's limit on a comment is 65,536 characters; long lists are cut well before it. */
    private const MAX_LENGTH = 60000;

    public static function render(Findings $findings, string $mainProjectRef, bool $picturesOnly = false): string
    {
        $failures = $findings->failures();
        $reports = $findings->reports();

        $lines = $picturesOnly ? [self::PICTURES_MARKER, '## Picture Check (Launch Check 12)', ''] : [self::MARKER, '## Launch Checks', ''];
        $lines[] = $failures === []
            ? '**Passed.** '.self::count(count($reports), 'report').' for the reviewer below.'
            : '**Failed:** '.self::count(count($failures), 'failure').' to fix, each naming its file, line and rule.';
        $lines[] = '';

        if ($failures !== []) {
            $lines[] = '### Failures';
            $lines[] = '';
            foreach ($failures as $finding) {
                $lines[] = self::line($finding);
            }
            $lines[] = '';
        }

        if ($reports !== []) {
            $lines[] = '<details'.($failures === [] ? ' open' : '').'><summary><strong>Reports</strong> ('.count($reports).'): for the reviewer to judge; these never fail the build</summary>';
            $lines[] = '';
            foreach ($reports as $finding) {
                $lines[] = self::line($finding);
            }
            $lines[] = '';
            $lines[] = '</details>';
            $lines[] = '';
        }

        foreach ($findings->checksNotRun() as $notRun) {
            $lines[] = "_Not run:_ {$notRun}.";
        }
        $lines[] = '';
        $lines[] = "<sub>Main project read at `{$mainProjectRef}`. Run locally: `php artisan help:launch-checks --main-project=<path to a firstmilemanager checkout> --stockist=<path to a stockist checkout>".($picturesOnly ? ' --pictures-only --take-pictures=changed --pictures-base=origin/main' : '').'`.</sub>';

        $markdown = implode("\n", $lines)."\n";

        return strlen($markdown) > self::MAX_LENGTH
            ? substr($markdown, 0, self::MAX_LENGTH)."\n\n_The list is cut here; the build log has every line._\n"
            : $markdown;
    }

    /** One finding as a Markdown list item: where, rule, then the message. */
    public static function line(Finding $finding): string
    {
        $message = str_replace(["\r", "\n"], ' ', $finding->message);

        return "- `{$finding->where()}` **{$finding->rule}**: ".htmlspecialchars($message, ENT_NOQUOTES);
    }

    private static function count(int $count, string $noun): string
    {
        return $count.' '.str($noun)->plural($count);
    }
}
