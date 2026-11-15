<?php

namespace App\Site;

use App\Content\AudienceTier;
use App\Content\ContentLibrary;
use App\Content\RolePath;

/**
 * The pages search engines may index once the help centre goes public (spec #30, user story
 * 136): the help home, the Role Paths and the other listings, each shown Product Area, Published
 * Articles of both tiers, both Glossaries and their entries, and What's new with each Release
 * Note. Nothing else is ever indexed: search results, retired notices, the pages after a vote,
 * redirects (such as /privacy) and the addresses that record something. The sitemap lists
 * exactly these, and each answers 200 without "noindex" once going public is on.
 */
final class IndexedPages
{
    public function __construct(private readonly ContentLibrary $library) {}

    /** @return list<IndexedPage> */
    public function all(): array
    {
        $pages = [new IndexedPage('/')];
        foreach (RolePath::cases() as $role) {
            $pages[] = new IndexedPage(Addresses::rolePath($role));
        }
        $pages[] = new IndexedPage('/getting-started');
        foreach ($this->library->shownProductAreas() as $area) {
            $pages[] = new IndexedPage(Addresses::productArea($area));
        }
        $pages[] = new IndexedPage('/for-your-clients');
        $pages[] = new IndexedPage('/troubleshooting');

        foreach ($this->library->publishedArticles() as $article) {
            $pages[] = new IndexedPage($article->address(), $article->checked_on);
        }

        foreach ([AudienceTier::Staff, AudienceTier::Client] as $tier) {
            $pages[] = new IndexedPage($tier === AudienceTier::Client ? '/client-glossary' : '/glossary');
            foreach ($this->library->glossaryEntries($tier) as $entry) {
                $pages[] = new IndexedPage($entry->address(), $entry->checked_on);
            }
        }

        $pages[] = new IndexedPage('/whats-new');
        foreach ($this->library->releaseNotes() as $note) {
            $pages[] = new IndexedPage($note->address(), $note->released_on);
        }

        return $pages;
    }
}
