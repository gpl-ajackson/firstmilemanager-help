<?php

namespace App\LaunchChecks\Pages;

use App\Content\Article;
use App\Content\ContentLibrary;
use App\Content\GlossaryEntry;
use App\Content\ReleaseNote;
use App\Content\RolePath;
use App\LaunchChecks\CheckContext;
use App\Site\Addresses;

/**
 * Every page a reader can open in the content the help centre holds now (rebuilt from the
 * content under test), not a sample: the home page, the listings, every Published Article,
 * Glossary Entry and Release Note, search results with and without results, each Retired
 * Article's notice and the page not found. Each is named with the template it is drawn with,
 * where Launch Check 11 times that template.
 */
final readonly class PublishedPages
{
    /** Words that find nothing, for the "No results" page. */
    public const NOTHING_FOUND = 'qqzx';

    /** An address with no page, for the page not found. */
    public const NOT_FOUND = '/no-such-page';

    public function __construct(private ContentLibrary $library) {}

    /** @return list<Page> */
    public function of(CheckContext $context): array
    {
        $articles = $this->library->publishedArticles()->sortBy('name')->values();

        $pages = [
            new Page('/', Page::HOME),
            new Page('/getting-started'),
            new Page('/for-your-clients'),
            new Page('/troubleshooting'),
            new Page('/glossary'),
            new Page('/client-glossary'),
            new Page('/whats-new'),
            ...array_map(fn (RolePath $role): Page => new Page(Addresses::rolePath($role), Page::ROLE_PATH), RolePath::cases()),
            ...array_map(fn (string $area): Page => new Page(Addresses::productArea($area), Page::PRODUCT_AREA), $this->library->shownProductAreas()),
            ...$articles->map(fn (Article $article): Page => new Page(
                "/articles/{$article->name}", "{$article->type->value} Article", $article->source_path,
            ))->all(),
            ...$this->library->glossaryEntries()->map(fn (GlossaryEntry $entry): Page => new Page(
                $entry->address(), Page::GLOSSARY_ENTRY, $entry->source_path,
            ))->all(),
            ...$this->library->releaseNotes()->map(fn (ReleaseNote $note): Page => new Page(
                $note->address(), Page::RELEASE_NOTE, $note->source_path,
            ))->all(),
        ];

        $first = $articles->sortBy('title')->first();
        $pages[] = new Page('/search');
        if ($first !== null) {
            $pages[] = new Page('/search?q='.rawurlencode($first->title), Page::SEARCH_RESULTS);
        }
        $pages[] = new Page('/search?q='.self::NOTHING_FOUND);

        foreach ($context->articles() as $file) {
            if (($file->header['status'] ?? null) === 'Retired' && $this->library->retiredArticle($file->name) !== null) {
                $pages[] = new Page("/articles/{$file->name}", Page::RETIRED_NOTICE, $file->file, 410);
            }
        }
        $pages[] = new Page(self::NOT_FOUND, Page::PAGE_NOT_FOUND, status: 404);

        return $pages;
    }
}
