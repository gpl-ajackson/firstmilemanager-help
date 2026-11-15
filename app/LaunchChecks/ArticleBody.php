<?php

namespace App\LaunchChecks;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;

/**
 * An Article's body as the checks read it, parsed as the Renderer parses it (CommonMark and
 * tables), with every line counted from the top of the file.
 */
final readonly class ArticleBody
{
    private function __construct(private Document $document, private int $offset) {}

    public static function of(ArticleFile $article): self
    {
        $environment = new Environment;
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);

        return new self((new MarkdownParser($environment))->parse($article->body), $article->bodyStartLine - 1);
    }

    /**
     * The body's headings of one level, in order, each without its Section Name (" {#name}").
     *
     * @return list<array{text: string, line: int}>
     */
    public function headings(int $level = 2): array
    {
        $headings = [];
        foreach ($this->document->iterator() as $node) {
            if ($node instanceof Heading && $node->getLevel() === $level) {
                $text = (string) preg_replace('/\s*\{#[^}]*\}\s*$/', '', trim(self::textOf($node)));
                $headings[] = ['text' => $text, 'line' => $this->lineOf($node)];
            }
        }

        return $headings;
    }

    /**
     * Each sentence the writer wrote in a paragraph or list item; quoted messages (block quotes)
     * are Stockist's words, so they are left out.
     *
     * @return list<array{text: string, words: int, line: int}>
     */
    public function sentences(): array
    {
        $sentences = [];
        foreach ($this->document->iterator() as $node) {
            if (! $node instanceof Paragraph || self::isQuoted($node)) {
                continue;
            }
            $text = self::textOf($node);
            $parts = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_OFFSET_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($parts as [$sentence, $offset]) {
                $sentences[] = [
                    'text' => (string) preg_replace('/\s+/', ' ', trim($sentence)),
                    'words' => self::countWords($sentence),
                    'line' => $this->lineOf($node) + substr_count(substr($text, 0, $offset), "\n"),
                ];
            }
        }

        return $sentences;
    }

    /**
     * Every numbered list: a run of steps.
     *
     * @return list<array{steps: int, line: int}>
     */
    public function stepRuns(): array
    {
        $runs = [];
        foreach ($this->document->iterator() as $node) {
            if ($node instanceof ListBlock && $node->getListData()->type === ListBlock::TYPE_ORDERED) {
                $steps = count(array_filter(iterator_to_array($node->children(), false), fn (Node $child): bool => $child instanceof ListItem));
                $runs[] = ['steps' => $steps, 'line' => $this->lineOf($node)];
            }
        }

        return $runs;
    }

    /**
     * The writer's own words, block by block, each with the file line it starts on: paragraphs,
     * list items, headings (without their Section Name), table cells, link words and the text of
     * Mermaid diagrams. Left out: quoted Stockist messages (block quotes) and what a reader scans
     * or types (code spans), which are Stockist's own words and data. Line breaks are kept, so a
     * place in a block's text gives its line.
     *
     * @return list<array{text: string, line: int}>
     */
    public function prose(): array
    {
        $prose = [];
        foreach ($this->document->iterator() as $node) {
            if (self::isQuoted($node)) {
                continue;
            }
            if ($node instanceof FencedCode && ($node->getInfoWords()[0] ?? '') === 'mermaid') {
                $prose[] = ['text' => $node->getLiteral(), 'line' => $this->lineOf($node) + 1];
            } elseif ($node instanceof Paragraph || $node instanceof Heading || $node instanceof TableCell) {
                $text = self::textOf($node, codeAs: ' ');
                if ($node instanceof Heading) {
                    $text = (string) preg_replace('/\s*\{#[^}]*\}\s*$/', '', $text);
                }
                $prose[] = ['text' => $text, 'line' => $this->lineOf($node)];
            }
        }

        return $prose;
    }

    /** The words a reader reads: every piece of text, code spans included. */
    public function words(): int
    {
        $words = 0;
        foreach ($this->document->iterator() as $node) {
            if ($node instanceof Text || $node instanceof Code) {
                $words += self::countWords($node->getLiteral());
            }
        }

        return $words;
    }

    private function lineOf(AbstractBlock $block): int
    {
        // A table cell has no line of its own; its row or table has.
        for ($node = $block; $node instanceof AbstractBlock; $node = $node->parent()) {
            if ($node->getStartLine() !== null) {
                return $this->offset + $node->getStartLine();
            }
        }

        return $this->offset + 1;
    }

    /** A block's text; $codeAs stands in for each code span when given. */
    private static function textOf(Node $node, ?string $codeAs = null): string
    {
        $text = '';
        foreach ($node->iterator() as $inline) {
            if ($inline instanceof Code && $codeAs !== null) {
                $text .= $codeAs;
            } elseif ($inline instanceof Text || $inline instanceof Code) {
                $text .= $inline->getLiteral();
            } elseif ($inline instanceof Newline) {
                $text .= "\n";
            }
        }

        return $text;
    }

    private static function isQuoted(Node $node): bool
    {
        for ($parent = $node->parent(); $parent !== null; $parent = $parent->parent()) {
            if ($parent instanceof BlockQuote) {
                return true;
            }
        }

        return false;
    }

    private static function countWords(string $text): int
    {
        return (int) preg_match_all("/[\\p{L}\\p{N}][\\p{L}\\p{N}'’&-]*/u", $text);
    }
}
