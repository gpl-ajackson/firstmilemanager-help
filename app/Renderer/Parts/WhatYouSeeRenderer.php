<?php

namespace App\Renderer\Parts;

use InvalidArgumentException;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Quotes each message in its own quote block, word for word, with each Placeholder (the part
 * that changes with the reader's own data) marked as <var>, so the reader can match it to the
 * message on their screen.
 */
final class WhatYouSeeRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        if (! $node instanceof WhatYouSee) {
            throw new InvalidArgumentException('Incompatible node type: '.$node::class);
        }

        $quotes = array_map(function (string $message): string {
            $text = htmlspecialchars($message, ENT_QUOTES | ENT_HTML5);

            return '<blockquote class="message"><p>'
                .preg_replace('/\[[^\[\]]+\]/', '<var>$0</var>', $text)
                .'</p></blockquote>';
        }, $node->messages);

        return '<section class="what-you-see"><h2>What you see</h2>'."\n".implode("\n", $quotes)."\n</section>";
    }
}
