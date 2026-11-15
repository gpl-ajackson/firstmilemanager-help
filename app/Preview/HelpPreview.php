<?php

namespace App\Preview;

use App\Content\Article;
use App\Content\ContentLibrary;
use App\Content\InvalidContent;
use App\Site\IndexedPage;
use App\Site\IndexedPages;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Help Previews (spec #30, user stories 126 to 128; #59): a static copy of every page readers
 * would see for a pull request's content, written to a folder that Cloudflare Pages serves at
 * the same addresses as the help centre, behind the same Cloudflare Access gate.
 *
 * The content under test is loaded into the help centre's database in a transaction that is
 * always rolled back (as the Launch Checks' page checks do), and each page is asked of the app
 * itself, in this process, with the preview switched on (isOn()): Draft Articles are shown,
 * marked as drafts; search and feedback forms send nothing; no page loads a script; every page
 * says noindex and carries the "Help Preview" bar; and nothing is recorded. Being static, the
 * preview never reaches the help centre's server once it is written.
 */
final readonly class HelpPreview
{
    /** The preview-only page listing the pull request's Draft Articles, linked from the preview bar. */
    public const DRAFTS_PAGE = '/help-preview';

    public function __construct(
        private Kernel $kernel,
        private DatabaseManager $database,
        private ContentLibrary $library,
        private IndexedPages $indexed,
    ) {}

    /** Whether a page is being drawn for a Help Preview (only ever while export() runs). */
    public static function isOn(): bool
    {
        return config('help.preview') !== null;
    }

    /** The pull request the preview being drawn is of, when it was given. */
    public static function pullRequest(): ?int
    {
        $pullRequest = config('help.preview.pull_request');

        return is_int($pullRequest) ? $pullRequest : null;
    }

    /**
     * Writes the Help Preview of the content folder at $contentPath (Pictures from
     * $picturesPath) into the folder $into, which must be new or empty. Changes nothing in the
     * help centre's database or search index.
     *
     * @throws InvalidContent when the content can't be rebuilt (nothing is written).
     * @throws UnusableFolder when $into holds files already.
     */
    public function export(string $contentPath, ?string $picturesPath, string $into, ?int $pullRequest = null): ExportedPreview
    {
        if (is_dir($into) && File::isEmptyDirectory($into) === false) {
            throw new UnusableFolder("{$into} isn't empty: give a new or empty folder for the Help Preview");
        }
        if (file_exists($into) && ! is_dir($into)) {
            throw new UnusableFolder("{$into} is a file: give a new or empty folder for the Help Preview");
        }

        $connection = $this->database->connection();
        $was = config('help.preview');
        $connection->beginTransaction();
        try {
            $this->library->rebuild($contentPath, $picturesPath);
            config(['help.preview' => ['pull_request' => $pullRequest]]);

            $drafts = $this->library->draftArticles();
            $copy = new StaticCopy($this->kernel, $into);
            $copy->page(self::DRAFTS_PAGE, view('preview.drafts', ['drafts' => $drafts])->render());
            $copy->follow([
                ...array_map(fn (IndexedPage $page): string => $page->address, $this->indexed->all()),
                ...$drafts->map(fn (Article $draft): string => $draft->address())->all(),
                ...$this->library->retiredNotices()->map(fn (Article $retired): string => $retired->address())->all(),
            ]);
            $copy->redirects($this->library->redirects());
            $copy->finish();

            return new ExportedPreview(pages: $copy->pages(), drafts: $drafts->count(), files: $copy->files());
        } catch (Throwable $failure) {
            // Half a preview is never deployed: the folder was new or empty, so it is emptied again.
            File::deleteDirectory($into, preserve: true);

            throw $failure;
        } finally {
            config(['help.preview' => $was]);
            $connection->rollBack();
        }
    }
}
