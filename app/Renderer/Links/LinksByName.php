<?php

namespace App\Renderer\Links;

use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;

/**
 * Links by name (spec #30, Renderer; Further Notes 2). Writers never write an address for a page
 * of the help centre; they write a name, as a Markdown link's destination:
 *
 *     [Book in a delivery](article:book-in-a-delivery)             an Article
 *     [what to check](article:book-in-a-delivery#check-the-asn)   a Section of an Article
 *     [the next part](#too-small)                                   a Section of this Article
 *     [pick list](term:pick-list)                                   a term's Glossary Entry
 *     [](article:book-in-a-delivery)                                no words: the Article's title
 *                                                                    (a Section's heading, a term's words)
 *
 * Only the first use of a term on a page is linked to its Glossary Entry; a later link to the
 * same term shows its words alone, so a writer may link a term wherever it reads well.
 *
 * Each is resolved for the page's readers: a term on a Staff page leads to `/glossary/<term>`,
 * on a Client-tier page to `/client-glossary/<term>`; a Client-tier page links only to Client-tier
 * Articles; a Published page never links to a Draft, and nothing links to a Retired Article.
 * Addresses are paths, the same on every help centre (SR4's branded centres serve the same paths),
 * so nothing in the files changes for a centre.
 *
 * A link that leads nowhere (a missing Article, Section or term, one a reader of this page may not
 * open, or one written by address or in a form the help centre doesn't know) is shown as its
 * words alone, and comes back with its line and problem, for the rebuild to list and the Links
 * check (#53) to fail. Web addresses (https:, http:, mailto:, tel:) and paths to the help centre's
 * other pages (`/areas/inbound`, `/getting-started`) are left as written, when that page is there
 * for the page's readers (a Product Area with a Published Article, or for a Draft any Article; a
 * Role Path; a Release Note), except that a Client-tier page may link by path only to the Client
 * Glossary (`/client-glossary`): its other paths lead to staff help, so they lead nowhere too.
 * A path to no page, and a web address without its scheme (`//example.com`), lead nowhere.
 */
final class LinksByName
{
    private const WEB = '/\A(?:https?:|mailto:|tel:)/i';

    /** Ends the problem of every link that would take a Client-tier reader to staff help. */
    private const OUT_OF_TIER = 'a Client-tier page links only to Client-tier ';

    /** Paths of pages that have a name to link by instead. */
    private const NAMED_PAGE_PATH = '#\A/(?:articles|glossary|client-glossary)/#';

    /** Pages for clients that a Client-tier page may link by path. */
    private const CLIENT_TIER_PATH = '#\A/client-glossary(?:[?\#]|\z)#';

    public function __construct(
        private readonly LinkTargets $targets,
        private readonly LinkingPage $page,
    ) {}

    /**
     * Resolves every link in $document, rendered from $markdown, in place.
     *
     * @return list<NamedLink> Every link by name, or that should have been, in page order.
     */
    public function resolve(Document $document, string $markdown): array
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $claimed = [];

        $links = array_filter(iterator_to_array($document->iterator(), false), fn (Node $node): bool => $node instanceof Link);
        $named = [];
        $termsLinked = [];
        foreach ($links as $link) {
            /** @var Link $link */
            $written = $link->getUrl();
            if (preg_match(self::WEB, $written) === 1
                || ($this->isOtherPage($written) && (! $this->page->clientTier || $this->isClientTierPage($written)) && $this->opens($written))) {
                continue;
            }
            $line = $this->lineOf($link, $written, $lines, $claimed);
            $resolved = $this->named($written, $line);

            // Only a term's first use is linked: a later link to it shows its words alone.
            if ($resolved->kind === NamedLink::TERM && $resolved->resolves()) {
                if (isset($termsLinked[(string) $resolved->name])) {
                    if ($link->firstChild() === null) {
                        $link->appendChild(new Text((string) $this->wordsFor($resolved)));
                    }
                    self::unlink($link);
                    $named[] = new NamedLink(NamedLink::TERM, $written, $line, $resolved->name, laterUse: true);

                    continue;
                }
                $termsLinked[(string) $resolved->name] = true;
            }

            if ($resolved->resolves()) {
                $link->setUrl((string) $resolved->address);
                if ($link->firstChild() === null) {
                    $words = $this->wordsFor($resolved);
                    if ($words === null) {
                        $resolved = new NamedLink($resolved->kind, $written, $line, $resolved->name, $resolved->section,
                            problem: 'give the link its words: [words]('.$written.')');
                    } else {
                        $link->appendChild(new Text($words));
                    }
                }
            }
            if (! $resolved->resolves()) {
                self::unlink($link);
            }
            $named[] = $resolved;
        }

        return $named;
    }

    /** A path to one of the help centre's other pages (a listing, a Glossary), left as written when it opens. */
    private function isOtherPage(string $written): bool
    {
        return str_starts_with($written, '/') && ! str_starts_with($written, '//') && preg_match(self::NAMED_PAGE_PATH, $written) !== 1;
    }

    /** Whether the page at a path is there for this page's readers. */
    private function opens(string $path): bool
    {
        $page = $this->targets->findPage($path);

        return $page === true || ($page === false && $this->page->draft);
    }

    /** The Client Glossary is the only page besides Client-tier Articles that is for clients. */
    private function isClientTierPage(string $path): bool
    {
        return preg_match(self::CLIENT_TIER_PATH, $path) === 1;
    }

    /** Whether a link's problem is that it would take a Client-tier reader out of the Client tier. */
    public static function isOutOfTier(string $problem): bool
    {
        return str_contains($problem, self::OUT_OF_TIER);
    }

    /** One of the page's related articles, from its header data, resolved the same way as a link. */
    public function relatedArticle(string $name, int $line): NamedLink
    {
        if ($name === $this->page->name) {
            return new NamedLink(NamedLink::ARTICLE, $name, $line, $name, problem: 'an Article is not related to itself');
        }

        return $this->article($name, null, $name, $line);
    }

    private function named(string $written, int $line): NamedLink
    {
        if (preg_match('/\Aarticle:([^#]*)(?:#(.*))?\z/', $written, $match) === 1) {
            return $this->article($match[1], $match[2] ?? null, $written, $line);
        }
        if (preg_match('/\A#(.*)\z/', $written, $match) === 1) {
            return $this->section($match[1], $written, $line);
        }
        if (preg_match('/\Aterm:(.*)\z/', $written, $match) === 1) {
            return $this->term($match[1], $written, $line);
        }

        if (str_starts_with($written, '//')) {
            return new NamedLink(NamedLink::NOT_BY_NAME, $written, $line, problem: 'write a web address in full, starting https://');
        }
        if ($this->isOtherPage($written)) {
            $path = (string) strtok($written, '?#');

            return new NamedLink(NamedLink::NOT_BY_NAME, $written, $line, problem: match (true) {
                $this->page->clientTier && ! $this->isClientTierPage($written) => 'is not a Client-tier page; '.self::OUT_OF_TIER.'pages',
                $this->targets->findPage($written) === false => "{$path} has no Published Article yet, so readers can't open it",
                default => "there is no page at {$path}",
            });
        }

        return new NamedLink(NamedLink::NOT_BY_NAME, $written, $line, problem: str_starts_with($written, '/')
            ? 'link a page of the help centre by name, not by address: article:<name>, article:<name>#<section> or term:<term>'
            : 'is not a link by name: write article:<name>, article:<name>#<section>, #<section> or term:<term>, or a full web address');
    }

    private function article(string $name, ?string $section, string $written, int $line): NamedLink
    {
        $problem = fn (string $problem): NamedLink => new NamedLink(NamedLink::ARTICLE, $written, $line, $name, $section, problem: $problem);

        $target = $this->targets->findArticle($name);
        if ($target === null) {
            return $problem("there is no Article named \"{$name}\"");
        }
        if ($target['status'] === 'Retired') {
            return $problem("\"{$name}\" is Retired; link the Article that replaces it");
        }
        if ($target['status'] === 'Draft' && ! $this->page->draft && ! ($target['waitingForDemo'] && $this->page->forNextRelease)) {
            return $problem($target['waitingForDemo']
                ? "\"{$name}\" waits for its feature to reach the demo (content/features.yaml), so readers can't open it yet"
                : "\"{$name}\" is a Draft, so readers can't open it yet");
        }
        if ($target['jointOnly'] && ! $this->page->jointOnly) {
            return $problem("\"{$name}\" is Joint-only, so only a Joint-only Article links to it: a reader at an FMM-only Operator never meets Stockist");
        }
        if ($this->page->clientTier && ! $target['clientTier']) {
            return $problem("\"{$name}\" is for Staff; ".self::OUT_OF_TIER.'Articles');
        }
        if ($section === null) {
            return new NamedLink(NamedLink::ARTICLE, $written, $line, $name, address: $target['address']);
        }
        if (! array_key_exists($section, $target['sections'])) {
            return $problem("\"{$name}\" has no Section named \"{$section}\"");
        }

        return new NamedLink(NamedLink::SECTION, $written, $line, $name, $section, $target['address'].'#'.$section);
    }

    private function section(string $section, string $written, int $line): NamedLink
    {
        $sections = $this->targets->findArticle($this->page->name)['sections'] ?? [];
        if (! array_key_exists($section, $sections)) {
            return new NamedLink(NamedLink::SECTION, $written, $line, section: $section, problem: "this Article has no Section named \"{$section}\"");
        }

        return new NamedLink(NamedLink::SECTION, $written, $line, section: $section, address: '#'.$section);
    }

    private function term(string $term, string $written, int $line): NamedLink
    {
        $target = $this->targets->findTerm($term, $this->page->clientTier);
        if ($target === null) {
            return new NamedLink(NamedLink::TERM, $written, $line, $term, problem: $this->page->clientTier
                ? "there is no Client Glossary entry for \"{$term}\""
                : "there is no Glossary Entry for \"{$term}\"");
        }

        return new NamedLink(NamedLink::TERM, $written, $line, $term, address: $target['address']);
    }

    /** The words a link written with none shows: the Article's title, the Section's heading or the term. */
    private function wordsFor(NamedLink $link): ?string
    {
        $article = $this->targets->findArticle($link->name ?? $this->page->name);

        return match ($link->kind) {
            NamedLink::ARTICLE => $article['title'] ?? null,
            NamedLink::SECTION => $article['sections'][(string) $link->section] ?? null,
            NamedLink::TERM => $this->targets->findTerm((string) $link->name, $this->page->clientTier)['words'] ?? null,
            default => null,
        };
    }

    /** Leaves the link's words in its place, as plain text. */
    private static function unlink(Link $link): void
    {
        foreach (iterator_to_array($link->children(), false) as $child) {
            $link->insertBefore($child);
        }
        $link->detach();
    }

    /**
     * The line a link is written on: the first line of its block holding its destination that an
     * earlier link hasn't claimed, else (a reference link) anywhere in the Markdown, else its
     * block's first line.
     *
     * @param  list<string>  $lines
     * @param  array<string, int>  $claimed  How many times each destination has been found so far.
     */
    private function lineOf(Link $link, string $written, array $lines, array &$claimed): int
    {
        $block = $link->parent();
        while ($block !== null && ! ($block instanceof AbstractBlock && $block->getStartLine() !== null)) {
            $block = $block->parent();
        }
        $start = $block instanceof AbstractBlock ? (int) $block->getStartLine() : 1;
        $end = $block instanceof AbstractBlock ? (int) ($block->getEndLine() ?? $start) : count($lines);

        $key = $start.':'.$written;
        $seen = $claimed[$key] ?? 0;
        $claimed[$key] = $seen + 1;
        for ($number = $start; $number <= $end; $number++) {
            $found = substr_count($lines[$number - 1] ?? '', $written);
            if ($seen < $found) {
                return $number;
            }
            $seen -= $found;
        }
        foreach ($lines as $index => $text) {
            if (str_contains($text, $written)) {
                return $index + 1;
            }
        }

        return $start;
    }
}
