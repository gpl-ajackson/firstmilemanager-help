<?php

namespace App\Content;

use Carbon\CarbonImmutable;
use Symfony\Component\Finder\Finder;

/**
 * One Release Note file, read and checked: the dated, plain-language record of what one
 * FMM Release changed, for Staff (GLOSSARY.md, Release Note). One file per Stockist
 * Release, content/release-notes/<yyyy-mm-dd>.md, named by the release's date, which is also its
 * address (/whats-new/<yyyy-mm-dd>):
 *
 *     ---
 *     summary: Delivery numbers now carry their warehouse.    one sentence for What's new
 *     changes:                                                 its Change Summaries
 *       - product_area: Inbound
 *         team_roles: [Admin, Warehouse Operative]
 *         affects_clients: true
 *         articles: [book-in-a-shipment]
 *         text: Delivery numbers now carry the warehouse they belong to.
 *     ---
 *
 *     Optional words before the changes, in Markdown (the first Release Note's welcome).
 *
 * `php artisan help:release-note` writes one from the Next Release folder. A Release Note is
 * history: it is never reviewed or retired, has no feedback and is never searched.
 */
final readonly class ReleaseNoteSource
{
    use HeaderData;

    public const FOLDER = 'release-notes';

    public const FIELDS = ['summary', 'changes'];

    public const SUMMARY_MAX = 160;

    private function __construct(
        /** The FMM Release's date: the file name without ".md". */
        public CarbonImmutable $date,
        public string $file,
        public string $summary,
        /** @var list<ChangeSummary> In the order written. */
        public array $changes,
        /** Words before the changes, in Markdown; empty when there are none. */
        public string $body,
        public int $bodyLine,
        /** @var list<string> The file's lines. */
        private array $lines,
    ) {}

    /** The Release Note's address: /whats-new/<yyyy-mm-dd>. */
    public function address(): string
    {
        return self::addressOf($this->date->toDateString());
    }

    public static function addressOf(string $date): string
    {
        return '/whats-new/'.$date;
    }

    /**
     * Every Release Note in $contentPath, oldest first; problems are added to $problems.
     *
     * @param  list<ContentProblem>  $problems
     * @return list<self>
     */
    public static function readAll(string $contentPath, array &$problems): array
    {
        $folder = $contentPath.'/'.self::FOLDER;
        if (! is_dir($folder)) {
            return [];
        }

        $notes = [];
        foreach (Finder::create()->files()->in($folder)->depth(0)->sortByName() as $file) {
            $note = self::read($file->getPathname(), self::FOLDER.'/'.$file->getFilename(), $problems);
            if ($note !== null) {
                $notes[] = $note;
            }
        }

        return $notes;
    }

    /**
     * @param  list<ContentProblem>  $problems
     */
    private static function read(string $path, string $file, array &$problems): ?self
    {
        $before = count($problems);
        $problem = function (string $field, string $message) use ($file, &$problems): void {
            $problems[] = new ContentProblem($file, $field, $message);
        };

        $name = basename($path);
        $date = preg_match('/\A(\d{4}-\d{2}-\d{2})\.md\z/', $name, $match) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $match[1]) : null;
        if ($date === null || $date->format('Y-m-d') !== $match[1]) {
            $problem('file', 'a Release Note is named by its FMM Release\'s date, YYYY-MM-DD.md, such as 2026-11-14.md');

            return null;
        }

        $contents = (string) file_get_contents($path);
        $read = self::frontMatter($contents, $problem);
        if ($read === null) {
            return null;
        }
        [$header, $yaml, $rest] = $read;

        foreach (array_keys($header) as $field) {
            if (! in_array($field, self::FIELDS, true)) {
                $problem((string) $field, 'is not a Release Note header data field (known fields: '.implode(', ', self::FIELDS).')');
            }
        }
        if (self::isMissing($header, 'summary')) {
            $problem('summary', 'is missing: say in one sentence what the release changed, for What\'s new');
        }
        $summary = self::text($header, 'summary', self::SUMMARY_MAX, $problem);
        $changes = self::changes($header, $problem);

        $body = trim($rest);
        $headerLines = preg_split('/\R/', $yaml) ?: [];
        $bodyLine = count($headerLines) + 3 + substr_count(substr($rest, 0, strspn($rest, " \t\r\n")), "\n");
        if (self::isMissing($header, 'changes') && $body === '') {
            $problem('changes', 'a Release Note says what changed: list its changes, or write it after the header data');
        }

        if (count($problems) > $before || $summary === null) {
            return null;
        }

        return new self($date, $file, $summary, $changes, $body, $bodyLine, preg_split('/\R/', $contents) ?: []);
    }

    /**
     * @param  array<mixed>  $header
     * @param  callable(string, string): void  $problem
     * @return list<ChangeSummary>
     */
    private static function changes(array $header, callable $problem): array
    {
        if (self::isMissing($header, 'changes')) {
            return [];
        }
        $items = $header['changes'];
        if (! is_array($items) || ! array_is_list($items)) {
            $problem('changes', 'must be a list of changes, each starting "- product_area: <Product Area>"');

            return [];
        }

        $changes = [];
        foreach ($items as $index => $item) {
            $number = $index + 1;
            if (! is_array($item) || array_is_list($item)) {
                $problem('changes', "item {$number} must have the fields ".implode(', ', [...ChangeSummary::FIELDS, 'text']));

                continue;
            }
            $text = $item['text'] ?? '';
            $change = ChangeSummary::from($item, is_string($text) ? $text : '', [...ChangeSummary::FIELDS, 'text'],
                fn (string $field, string $message) => $problem('changes', "item {$number}: {$field} {$message}"));
            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    /** The file's line listing one of a change's Articles (its $occurrence-th listing in the file), or the changes field's line. */
    public function lineOfArticle(string $name, int $occurrence): int
    {
        return self::lineOfItem($this->lines, $name, $occurrence, $this->lineOf('changes:'));
    }

    /** The file's first line holding $text, or line 1. */
    public function lineOf(string $text): int
    {
        foreach ($this->lines as $index => $line) {
            if (str_contains($line, $text)) {
                return $index + 1;
            }
        }

        return 1;
    }

    /**
     * The line of the $occurrence-th list item "- <$item>" in $lines, counted from 1, or $otherwise.
     *
     * @param  list<string>  $lines
     */
    public static function lineOfItem(array $lines, string $item, int $occurrence = 1, int $otherwise = 1): int
    {
        $seen = 0;
        foreach ($lines as $index => $line) {
            if (preg_match('/\A\s*-\s*([\'"]?)'.preg_quote($item, '/').'\1\s*(?:#.*)?\z/', $line) === 1
                || preg_match('/\A\s*articles\s*:\s*\[.*\b'.preg_quote($item, '/').'\b.*\]/', $line) === 1) {
                if (++$seen === $occurrence) {
                    return $index + 1;
                }
            }
        }

        return $otherwise;
    }
}
