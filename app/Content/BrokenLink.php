<?php

namespace App\Content;

use Stringable;

/**
 * A link by name that leads nowhere for its page's readers, so they see its words as plain
 * text: a missing Article, Section or term, one they may not open, or one written by address.
 * The rebuild lists these and still publishes; the Links check (Launch Check 3) fails them.
 */
final readonly class BrokenLink implements Stringable
{
    public function __construct(
        /** The file, relative to the content folder. */
        public string $file,
        public int $line,
        /** "link" for a link in the body, or the header data field (related_articles). */
        public string $field,
        /** The link's destination, or the name in the header data, as written. */
        public string $written,
        public string $problem,
    ) {}

    /** articles/receive-a-delivery.md:14: link "article:book-in": there is no Article named "book-in" */
    public function __toString(): string
    {
        return "{$this->file}:{$this->line}: {$this->field} \"{$this->written}\": {$this->problem}";
    }
}
