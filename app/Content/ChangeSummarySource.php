<?php

namespace App\Content;

use Symfony\Component\Finder\Finder;

/**
 * One Change Summary file in the Next Release folder (content/next-release/), where a Help
 * Proposal leaves it until the next FMM Release's Release Note gathers it in:
 *
 *     ---
 *     product_area: Inbound                 the Product Area it belongs to, as the app's menu names it
 *     team_roles: [Admin]                   the Team Roles it affects
 *     affects_clients: true                 true when it changes what clients see
 *     articles: [book-in-a-shipment]        optional: the Articles it changed, by Article Name
 *     ---
 *
 *     Delivery numbers now carry the warehouse they belong to, such as `INB-W1-000023`.
 *
 * Any file name ending ".md" will do (a Help Proposal names it after its change, such as
 * stockist-215-warehouse-codes.md); README.md is the folder's guide for builders, never a
 * Change Summary. The rebuild checks every Change Summary, so a Help Proposal's is checked on
 * its pull request, but never shows one: readers see it once it is in a Release Note.
 */
final readonly class ChangeSummarySource
{
    use HeaderData;

    public const FOLDER = 'next-release';

    /** The folder's guide for builders, which is not a Change Summary. */
    public const GUIDE = 'README.md';

    private function __construct(
        /** The file, relative to the content folder. */
        public string $file,
        public ChangeSummary $summary,
        /** The file's line on which its sentences start. */
        public int $bodyLine,
        /** @var list<string> The file's lines. */
        private array $lines,
    ) {}

    /**
     * Every Change Summary waiting in the Next Release folder of $contentPath, by file name;
     * problems are added to $problems. No folder means none are waiting.
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

        $summaries = [];
        foreach (Finder::create()->files()->in($folder)->depth(0)->sortByName() as $file) {
            $relative = self::FOLDER.'/'.$file->getFilename();
            if ($file->getFilename() === self::GUIDE) {
                continue;
            }
            if ($file->getExtension() !== 'md') {
                $problems[] = new ContentProblem($relative, 'file', 'only Change Summaries, Markdown files ending ".md", belong in the next-release folder');

                continue;
            }
            $summary = self::read($file->getPathname(), $relative, $problems);
            if ($summary !== null) {
                $summaries[] = $summary;
            }
        }

        return $summaries;
    }

    /**
     * @param  list<ContentProblem>  $problems
     */
    private static function read(string $path, string $file, array &$problems): ?self
    {
        $problem = function (string $field, string $message) use ($file, &$problems): void {
            $problems[] = new ContentProblem($file, $field, $message);
        };

        $contents = (string) file_get_contents($path);
        $read = self::frontMatter($contents, $problem);
        if ($read === null) {
            return null;
        }
        [$header, $yaml, $rest] = $read;

        $summary = ChangeSummary::from($header, $rest, ChangeSummary::FIELDS, function (string $field, string $message) use ($problem): void {
            $field === 'text'
                ? $problem('body', 'the Change Summary has no sentences after its header data: say what a reader can now do or will notice, in one or two plain sentences')
                : $problem($field, $message);
        });
        if ($summary === null) {
            return null;
        }

        $headerLines = preg_split('/\R/', $yaml) ?: [];
        $bodyLine = count($headerLines) + 3 + substr_count(substr($rest, 0, strspn($rest, " \t\r\n")), "\n");

        return new self($file, $summary, $bodyLine, preg_split('/\R/', $contents) ?: []);
    }

    /** The file's line listing one of the Articles it changed, or line 1 when none is found. */
    public function lineOfArticle(string $name): int
    {
        return ReleaseNoteSource::lineOfItem($this->lines, $name, 1);
    }
}
