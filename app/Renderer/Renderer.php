<?php

namespace App\Renderer;

use App\Pictures\PictureLibrary;
use App\Renderer\Links\LinksByName;
use App\Renderer\Parts\InShort;
use App\Renderer\Parts\WhatYouSee;
use App\Renderer\Pictures\PicturesExtension;
use App\Renderer\Pictures\PlacePictures;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

/**
 * Turns an Article's Markdown into the HTML a reader sees (spec #30, Renderer). The content
 * library calls it once per rebuild and keeps the result, so no page parses Markdown while a
 * reader waits.
 *
 * Writers' raw HTML is escaped and unsafe links are dropped: everything on a page comes from
 * Markdown and the header data. On top of CommonMark it draws the Article Templates' parts:
 *
 * - A Section Name written at the end of a heading, `## The location is too small {#too-small}`,
 *   becomes that heading's id, so links into the Section survive a reworded heading. No other
 *   heading gets an id.
 * - The body's `## In short` Section is drawn as the boxed answer.
 * - A Troubleshooting Article's messages, from its header data, are shown under "What you see"
 *   after "In short", word for word, with each [Placeholder] marked.
 * - Pictures and Mermaid diagrams become sized images (Pictures\PicturesExtension), from the
 *   pictures folder given by withPictures().
 * - Links by name (`article:<name>`, `#<section>`, `term:<term>`) become the address for the
 *   page's readers (`Links\LinksByName`).
 * - A table scrolls sideways on a narrow screen rather than widening the page, so it can take
 *   the keyboard's focus too: a reader without a mouse can scroll it with the arrow keys.
 */
final class Renderer
{
    private const SECTION_NAME = '/\s*\{#([^{}]*)\}\s*\z/';

    private const SECTION_NAME_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    private readonly MarkdownParser $parser;

    private readonly HtmlRenderer $html;

    public function __construct(private readonly ?PictureLibrary $pictures = null)
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);
        $environment->addRenderer(InShort::class, new Parts\InShortRenderer);
        $environment->addRenderer(WhatYouSee::class, new Parts\WhatYouSeeRenderer);
        $environment->addExtension(new PicturesExtension($this->pictures));

        $this->parser = new MarkdownParser($environment);
        $this->html = new HtmlRenderer($environment);
    }

    /** The same Renderer, showing Pictures from (and drawing diagrams into) $pictures. */
    public function withPictures(PictureLibrary $pictures): self
    {
        return new self($pictures);
    }

    /**
     * @param  list<string>  $messages  A Troubleshooting Article's messages, from its header data.
     * @param  ?LinksByName  $links  What its links by name may lead to; without it they are left as written.
     */
    public function article(string $markdown, array $messages = [], ?LinksByName $links = null): RenderedArticle
    {
        return $this->render($this->parse($markdown, $messages), $links);
    }

    /**
     * The first half of article(): parses the body, names its Sections and places its Pictures,
     * so its Section Names and problems are known before any link is resolved.
     *
     * @param  list<string>  $messages  A Troubleshooting Article's messages, from its header data.
     */
    public function parse(string $markdown, array $messages = []): ParsedArticle
    {
        $document = $this->parser->parse($markdown);
        $problems = [];
        $sections = $this->nameSections($document, $problems);

        return new ParsedArticle($document, $markdown, $messages, $sections, $problems, self::pictureProblems($document));
    }

    /** The second half of article(): resolves the parsed body's links by name and renders it. */
    public function render(ParsedArticle $parsed, ?LinksByName $links = null): RenderedArticle
    {
        $document = $parsed->document();
        $named = $links?->resolve($document, $parsed->markdown) ?? [];
        $inShort = $this->boxInShort($document);
        self::focusTables($document);
        if ($parsed->messages !== []) {
            $whatYouSee = new WhatYouSee($parsed->messages);
            $inShort !== null ? $inShort->insertAfter($whatYouSee) : $document->prependChild($whatYouSee);
        }

        return new RenderedArticle(
            html: $this->html->renderDocument($document)->getContent(),
            sections: $parsed->sections,
            problems: $parsed->problems,
            pictureProblems: $parsed->pictureProblems,
            links: $named,
        );
    }

    /** Lets each table take the keyboard's focus, as it may scroll sideways (site.css). */
    private static function focusTables(Document $document): void
    {
        foreach ($document->iterator() as $node) {
            if ($node instanceof Table) {
                $node->data->set('attributes/tabindex', '0');
            }
        }
    }

    /**
     * Gives each heading written with a Section Name that name as its id, and takes the name
     * out of the heading's words.
     *
     * @param  list<string>  $problems
     * @return array<string, string> Each Section Name with its heading's words.
     */
    private function nameSections(Document $document, array &$problems): array
    {
        $headings = array_filter(iterator_to_array($document->iterator(), false), fn (Node $node): bool => $node instanceof Heading);

        $sections = [];
        foreach ($headings as $heading) {
            $last = $heading->lastChild();
            $name = null;
            if ($last instanceof Text && preg_match(self::SECTION_NAME, $last->getLiteral(), $marker) === 1) {
                $name = $marker[1];
                $last->setLiteral((string) preg_replace(self::SECTION_NAME, '', $last->getLiteral()));
            }
            $words = self::words($heading);

            if (str_contains($words, '{#')) {
                $problems[] = 'write it at the end of its heading, as "## Heading {#section-name}" ("'.$words.'")';
            } elseif ($name === null) {
                continue;
            } elseif (preg_match(self::SECTION_NAME_PATTERN, $name) !== 1) {
                $problems[] = "\"{$name}\" must be lower-case letters and numbers joined by single hyphens";
            } elseif (array_key_exists($name, $sections)) {
                $problems[] = "\"{$name}\" names two Sections";
            } else {
                $heading->data->set('attributes/id', $name);
                $sections[$name] = $words;
            }
        }

        return $sections;
    }

    /** Moves the "In short" heading and what follows it, up to the next Section, into its box. */
    private function boxInShort(Document $document): ?InShort
    {
        foreach ($document->children() as $node) {
            if ($node instanceof Heading && $node->getLevel() === 2 && strcasecmp(self::words($node), 'In short') === 0) {
                $box = new InShort;
                $node->replaceWith($box);
                $box->appendChild($node);
                while (($next = $box->next()) !== null && ! ($next instanceof Heading && $next->getLevel() <= 2)) {
                    $box->appendChild($next);
                }

                return $box;
            }
        }

        return null;
    }

    /** @return list<array{field: string, line: int, message: string}> */
    private static function pictureProblems(Document $document): array
    {
        /** @var list<array{field: string, line: int, message: string}> $problems */
        $problems = $document->data->get(PlacePictures::PROBLEMS, []);

        return $problems;
    }

    /** A heading's words as a reader reads them. */
    private static function words(Heading $heading): string
    {
        $words = '';
        foreach ($heading->iterator() as $node) {
            if ($node instanceof Text || $node instanceof Code) {
                $words .= $node->getLiteral();
            }
        }

        return trim($words);
    }
}
