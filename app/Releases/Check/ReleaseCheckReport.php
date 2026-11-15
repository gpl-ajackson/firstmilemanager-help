<?php

namespace App\Releases\Check;

use App\LaunchChecks\MarkdownReport;
use Illuminate\Support\Str;

/**
 * The Release Check as AJ reads it: in the terminal (lines()) and as Markdown for the
 * workflow's summary and artefact (markdown()). Both end, or start, with the plain answer:
 * whether help is complete for this release.
 */
final readonly class ReleaseCheckReport
{
    /** The hidden first line of the Markdown, so a later run can find an earlier one. */
    public const string MARKER = '<!-- help-release-check -->';

    public const string CHANGES = 'Pull requests merged since the last FMM Release';

    public const string DRAFT = 'Draft Release Note';

    public const string LAUNCH_CHECKS = 'Launch Checks against the version being released, with every Picture retaken';

    public const string PICTURES = 'Pictures that differ at the version being released';

    public function __construct(private ReleaseCheck $check, private string $releaseRef) {}

    /** @return list<string> What the command prints: a heading per part, its lines indented, the verdict last. */
    public function lines(): array
    {
        $check = $this->check;
        $lines = ["Release Check: Stockist {$this->stockist()}, since the last FMM Release ({$check->lastReleaseRef} at ".substr($check->lastRelease, 0, 12).').', ''];

        $lines[] = self::CHANGES.' ('.count($check->changes).'):';
        foreach ($check->changes as $help) {
            $lines[] = '  '.$help->line();
        }
        if ($check->changes === []) {
            $lines[] = '  None: nothing was merged into main since the last FMM Release.';
        }
        $lines[] = '';

        $lines[] = $this->draftHeading().':';
        if ($check->releaseNoteProblems !== []) {
            $lines[] = '  The Next Release folder has Change Summaries the rebuild would refuse:';
            foreach ($check->releaseNoteProblems as $problem) {
                $lines[] = "  {$problem}";
            }
        } elseif ($check->draft === null) {
            $lines[] = '  None: the Next Release folder has no Change Summaries, so this release has no Release Note.';
        } else {
            foreach (preg_split('/\R/', rtrim($check->draft->contents)) ?: [] as $line) {
                $lines[] = $line === '' ? '' : "  {$line}";
            }
        }
        $lines[] = '';

        $lines[] = self::LAUNCH_CHECKS.':';
        foreach ($check->contentFindings() as $finding) {
            $lines[] = '  '.($finding->fails ? 'FAIL' : 'REPORT')." {$finding}";
        }
        foreach ($check->findings->checksNotRun() as $notRun) {
            $lines[] = "  Not run: {$notRun}.";
        }
        if ($check->contentFindings() === [] && $check->findings->checksNotRun() === []) {
            $lines[] = '  None: no failures and no reports.';
        }
        $lines[] = '';

        $lines[] = self::PICTURES.':';
        foreach ($check->pictures() as $finding) {
            $lines[] = "  FAIL {$finding}";
        }
        if ($check->pictures() === []) {
            $lines[] = '  None: every Picture retaken is what its Recipe takes from Stockist '.substr($check->release, 0, 12).'.';
        }
        $lines[] = '';

        $lines[] = $check->verdict();

        return $lines;
    }

    public function markdown(): string
    {
        $check = $this->check;
        $lines = [self::MARKER, "## Release Check: Stockist {$this->stockist()}", ''];
        $lines[] = ($check->isComplete() ? '**Help is complete for this release.** ' : '**Help is not complete for this release:** ').self::escape(Str::after($check->verdict(), ': ')).'';
        $lines[] = '';
        $lines[] = "Since the last FMM Release: `{$check->lastReleaseRef}` at [`".substr($check->lastRelease, 0, 12).'`](https://github.com/'.MainProjectHistory::REPOSITORY."/commit/{$check->lastRelease}).";
        $lines[] = '';

        $lines[] = '### '.self::CHANGES.' ('.count($check->changes).')';
        $lines[] = '';
        foreach ($check->changes as $help) {
            $why = self::escape($help->why);
            foreach ($help->helpProposals as $proposal) {
                $why = str_replace($proposal->reference(), "[{$proposal->reference()}]({$proposal->address()})", $why);
            }
            $lines[] = "- **{$help->status->value}** [{$help->change->name()}]({$help->change->address()}) ".self::escape($help->change->title).": {$why}";
        }
        if ($check->changes === []) {
            $lines[] = 'None: nothing was merged into main since the last FMM Release.';
        }
        $lines[] = '';

        $lines[] = '### '.$this->draftHeading();
        $lines[] = '';
        if ($check->releaseNoteProblems !== []) {
            $lines[] = 'The Next Release folder has Change Summaries the rebuild would refuse:';
            $lines[] = '';
            foreach ($check->releaseNoteProblems as $problem) {
                $lines[] = '- '.self::escape((string) $problem);
            }
        } elseif ($check->draft === null) {
            $lines[] = 'None: the Next Release folder has no Change Summaries, so this release has no Release Note.';
        } else {
            $fence = str_repeat('`', max(3, self::longestRun($check->draft->contents, '`') + 1));
            $lines[] = "Publish it with `php artisan help:release-note --date={$check->draft->date->toDateString()}`, which writes this and empties the Next Release folder:";
            $lines[] = '';
            $lines[] = "{$fence}markdown";
            $lines[] = rtrim($check->draft->contents);
            $lines[] = $fence;
        }
        $lines[] = '';

        $lines[] = '### '.self::LAUNCH_CHECKS;
        $lines[] = '';
        foreach ($check->contentFindings() as $finding) {
            $lines[] = str_replace('- `', '- '.($finding->fails ? '**FAIL** `' : 'REPORT `'), MarkdownReport::line($finding));
        }
        foreach ($check->findings->checksNotRun() as $notRun) {
            $lines[] = '- _Not run:_ '.self::escape($notRun).'.';
        }
        if ($check->contentFindings() === [] && $check->findings->checksNotRun() === []) {
            $lines[] = 'None: no failures and no reports.';
        }
        $lines[] = '';

        $lines[] = '### '.self::PICTURES;
        $lines[] = '';
        foreach ($check->pictures() as $finding) {
            $lines[] = MarkdownReport::line($finding);
        }
        if ($check->pictures() === []) {
            $lines[] = 'None: every Picture retaken is what its Recipe takes from Stockist '.substr($check->release, 0, 12).'.';
        } else {
            $lines[] = '';
            $lines[] = 'The before, after and difference images are in the run\'s `picture-check` artefact.';
        }
        $lines[] = '';

        return implode("\n", $lines);
    }

    /** "abc123456789 (main)" */
    private function stockist(): string
    {
        $commit = substr($this->check->release, 0, 12);

        return $this->releaseRef === '' || $this->releaseRef === $commit ? $commit : "{$commit} ({$this->releaseRef})";
    }

    private function draftHeading(): string
    {
        $draft = $this->check->draft;
        if ($draft === null) {
            return self::DRAFT;
        }
        $count = count($draft->changeSummaries);

        return self::DRAFT." ({$draft->file}, from {$count} Change ".Str::plural('Summary', $count).': '.implode(', ', $draft->productAreas()).')';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars(str_replace(["\r", "\n"], ' ', $text), ENT_NOQUOTES);
    }

    private static function longestRun(string $text, string $character): int
    {
        preg_match_all('/'.preg_quote($character, '/').'+/', $text, $runs);

        return max([0, ...array_map('strlen', $runs[0])]);
    }
}
