<?php

namespace App\Renderer\Links;

/**
 * Everything in the content folder a link by name can lead to: each Article with its Section
 * Names, and each term's Glossary Entry for each Audience Tier; and the help centre's other
 * pages that a writer may link by path. The content library fills it once
 * per rebuild, after every Article's Section Names are known, so a link can be checked against
 * the whole folder.
 *
 * Addresses are the help centre's own paths, the same on Stockist's centre and on each 3PL's
 * branded centre (spec #30, Further Notes 1), so a page rendered once serves every centre.
 */
final class LinkTargets
{
    /** @var array<string, array{address: string, title: string, sections: array<string, string>, clientTier: bool, status: string, jointOnly: bool, waitingForDemo: bool}> */
    private array $articles = [];

    /** @var array<string, array{address: string, words: ?string}> keyed "staff:<term>" or "client:<term>" */
    private array $terms = [];

    /** @var array<string, bool> Each other page's path, and whether a Published page shows it. */
    private array $pages = [];

    /**
     * @param  array<string, string>  $sections  Each Section Name with its heading's words.
     * @param  'Published'|'Draft'|'Retired'  $status  'Draft' too for a Published Article waiting for the demo ($waitingForDemo)
     */
    public function article(string $name, string $address, string $title, array $sections, bool $clientTier, string $status, bool $jointOnly = false, bool $waitingForDemo = false): self
    {
        $this->articles[$name] = compact('address', 'title', 'sections', 'clientTier', 'status', 'jointOnly', 'waitingForDemo');

        return $this;
    }

    /**
     * A term's Glossary Entry for one Audience Tier: the staff Glossary, or the Client Glossary
     * when $clientTier.
     *
     * @param  string  $term  The term's name in its address (lower case, hyphens).
     * @param  ?string  $words  The term as readers read it, for a link written with no words.
     */
    public function term(string $term, string $address, bool $clientTier, ?string $words = null): self
    {
        $this->terms[($clientTier ? 'client:' : 'staff:').$term] = compact('address', 'words');

        return $this;
    }

    /**
     * Another page of the help centre that a writer may link by its path (a listing, a Role
     * Path, a Release Note, search). $published is false for a page only Drafts would put
     * there, such as a Product Area whose Articles are all Drafts.
     */
    public function page(string $path, bool $published = true): self
    {
        $this->pages[$path] = ($this->pages[$path] ?? false) || $published;

        return $this;
    }

    /**
     * Whether a path (any query or fragment aside) is one of the help centre's other pages:
     * null when there is no such page, false when only a Draft would put it there.
     */
    public function findPage(string $path): ?bool
    {
        return $this->pages[(string) strtok($path, '?#')] ?? null;
    }

    /** @return array{address: string, title: string, sections: array<string, string>, clientTier: bool, status: string, jointOnly: bool, waitingForDemo: bool}|null */
    public function findArticle(string $name): ?array
    {
        return $this->articles[$name] ?? null;
    }

    /** @return array{address: string, words: ?string}|null */
    public function findTerm(string $term, bool $clientTier): ?array
    {
        return $this->terms[($clientTier ? 'client:' : 'staff:').$term] ?? null;
    }
}
