<?php

namespace App\Reports;

use App\LaunchChecks\SourceReferences\HelpContentSection;
use App\Records\Records;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;

/**
 * The main project's history, read from its checkout with git (never from Stockist itself,
 * rule 21): which commits changed a cited plan or ADR after an Article's Checked On. The
 * checkout needs its whole history (actions/checkout with fetch-depth: 0).
 *
 * A file counts as changed only when what it says changed: its text now differs from its text
 * at the end of the Checked On day once each version's Help content section is taken out,
 * because every Help Proposal edits the Help content sections of the plans it touches, and
 * that isn't a change to what the help describes.
 */
final readonly class MainProjectHistory
{
    private function __construct(private string $path, public string $head) {}

    /** @throws UnreadableHistory */
    public static function of(string $path): self
    {
        $inside = Process::path($path)->run(['git', 'rev-parse', '--is-inside-work-tree', '--is-shallow-repository', 'HEAD']);
        $answers = explode("\n", trim($inside->output()));
        if ($inside->failed() || count($answers) < 3 || $answers[0] !== 'true') {
            throw new UnreadableHistory("{$path} isn't a git checkout of the main project with commits to read");
        }
        if ($answers[1] === 'true') {
            throw new UnreadableHistory("the main project's checkout is shallow, so its history can't be read: check it out with its whole history (fetch-depth: 0)");
        }

        return new self($path, $answers[2]);
    }

    /**
     * The commits after $day (a UK day) that changed what $file says, newest first; none when
     * its text, Help content aside, is as it was at the end of that day.
     *
     * @return list<FileChange>
     */
    public function changesAfter(string $file, CarbonImmutable $day): array
    {
        $endOfDay = CarbonImmutable::parse($day->format('Y-m-d'), Records::TIMEZONE)->endOfDay();
        $log = Process::path($this->path)->run(['git', 'log', '--format=%H%x1f%cI%x1f%s', 'HEAD', '--', $file]);
        if ($log->failed()) {
            return [];
        }

        $after = [];
        $before = null;
        foreach (array_filter(explode("\n", trim($log->output()))) as $line) {
            [$sha, $at, $subject] = array_pad(explode("\x1f", $line, 3), 3, '');
            $change = new FileChange($sha, CarbonImmutable::parse($at), $subject);
            if ($change->at->greaterThan($endOfDay)) {
                $after[] = $change;
            } else {
                $before ??= $change;
            }
        }
        if ($after === []) {
            return [];
        }

        $then = $before === null ? null : $this->textAt($before->sha, $file);
        $now = $this->textAt('HEAD', $file);

        return $then !== null && $now !== null && self::substance($then) === self::substance($now) ? [] : $after;
    }

    private function textAt(string $commit, string $file): ?string
    {
        $show = Process::path($this->path)->run(['git', 'show', "{$commit}:{$file}"]);

        return $show->successful() ? $show->output() : null;
    }

    /** What a file says, without its Help content section and with line endings and trailing space evened out. */
    private static function substance(string $markdown): string
    {
        return trim((string) preg_replace('/[ \t]+$/m', '', str_replace("\r\n", "\n", HelpContentSection::without($markdown))));
    }
}
