<?php

namespace App\Renderer\Pictures;

use App\Pictures\SizedPicture;
use InvalidArgumentException;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use Stringable;

/**
 * Draws a Picture or diagram as one image with its alt text and sized sources, and its size, so
 * the page doesn't move as it loads. No caption: the step's own words say what to do, so the
 * step reads the same with pictures off.
 */
final class PictureRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): Stringable
    {
        if ($node instanceof Picture) {
            return self::image($node->picture);
        }
        if ($node instanceof Diagram) {
            return new HtmlElement('div', ['class' => 'diagram'], self::image($node->picture));
        }

        throw new InvalidArgumentException('Incompatible node type: '.$node::class);
    }

    private static function image(SizedPicture $picture): HtmlElement
    {
        return new HtmlElement('img', [
            'class' => $picture->kind === 'diagram' ? 'diagram-image' : "picture picture-{$picture->kind}",
            'src' => $picture->src(),
            'srcset' => $picture->srcset(),
            'sizes' => $picture->sizes(),
            'width' => (string) $picture->width,
            'height' => (string) $picture->height,
            'alt' => $picture->alt,
            'loading' => 'lazy',
            'decoding' => 'async',
        ], '', true);
    }
}
