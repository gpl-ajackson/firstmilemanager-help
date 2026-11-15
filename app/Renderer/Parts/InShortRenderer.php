<?php

namespace App\Renderer\Parts;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use Stringable;

/** Draws "In short" in the Bay corner frame, the one key thing on an Article page. */
final class InShortRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): Stringable
    {
        InShort::assertInstanceOf($node);

        return new HtmlElement('section', ['class' => 'in-short corners'],
            new HtmlElement('div', ['class' => 'answer'], $childRenderer->renderNodes($node->children())));
    }
}
