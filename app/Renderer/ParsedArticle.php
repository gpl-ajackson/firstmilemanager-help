<?php

namespace App\Renderer;

use League\CommonMark\Node\Block\Document;
use LogicException;

/**
 * An Article's body parsed once (Renderer::parse()): its Section Names named and its Pictures
 * placed, but its links by name not yet resolved, so the content library can learn every
 * Article's Section Names before resolving any link, without parsing or placing Pictures twice.
 * Renderer::render() turns it into the RenderedArticle, once.
 */
final class ParsedArticle
{
    private bool $rendered = false;

    /**
     * @param  list<string>  $messages
     * @param  array<string, string>  $sections  Each Section Name with its heading's words.
     * @param  list<string>  $problems  Section Names written wrongly.
     * @param  list<array{field: string, line: int, message: string}>  $pictureProblems
     */
    public function __construct(
        public readonly Document $document,
        public readonly string $markdown,
        public readonly array $messages,
        public readonly array $sections,
        public readonly array $problems,
        public readonly array $pictureProblems,
    ) {}

    /** Hands the document over to be rendered; rendering changes it, so it happens once. */
    public function document(): Document
    {
        if ($this->rendered) {
            throw new LogicException('An Article body is rendered only once; parse it again to render it again.');
        }
        $this->rendered = true;

        return $this->document;
    }
}
