<?php

namespace App\Renderer;

use App\Renderer\Links\NamedLink;

/** An Article's body as a reader sees it, with what the rebuild keeps or reports about it. */
final readonly class RenderedArticle
{
    /**
     * @param  array<string, string>  $sections  Each Section Name with its heading's words.
     * @param  list<string>  $problems  Section Names written wrongly; the rebuild refuses the Article.
     * @param  list<array{field: string, line: int, message: string}>  $pictureProblems  Pictures and diagrams it can't show, by their line in the body; the rebuild refuses the Article.
     * @param  list<NamedLink>  $links  Its links by name, resolved or leading nowhere, in page order.
     */
    public function __construct(
        public string $html,
        public array $sections,
        public array $problems,
        public array $pictureProblems = [],
        public array $links = [],
    ) {}
}
