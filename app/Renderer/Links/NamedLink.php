<?php

namespace App\Renderer\Links;

/**
 * One link a writer wrote by name (or should have), with where it leads or why it leads
 * nowhere. A link with a problem is shown to readers as its words alone.
 */
final readonly class NamedLink
{
    public const ARTICLE = 'article';

    public const SECTION = 'section';

    public const TERM = 'term';

    /** A link not written by name: an Article or Glossary address, or a form the help centre doesn't know. */
    public const NOT_BY_NAME = 'not by name';

    public function __construct(
        /** What it names: one of the constants above. */
        public string $kind,
        /** The destination as written, such as "article:book-in-a-delivery#too-small". */
        public string $written,
        /** Its line in the Markdown it was rendered from (or the header data line for related articles). */
        public int $line,
        /** The Article Name or term it names; null for a link to this Article's own Section or one not by name. */
        public ?string $name = null,
        public ?string $section = null,
        /** Where it leads for this page's readers; null when it leads nowhere. */
        public ?string $address = null,
        /** Why it leads nowhere; null when it resolves. */
        public ?string $problem = null,
        /** A term already linked earlier on the page: shown as its words, with no address and no problem. */
        public bool $laterUse = false,
    ) {}

    public function resolves(): bool
    {
        return $this->problem === null;
    }
}
