<?php

namespace App\Renderer\Pictures;

use App\Pictures\PictureLibrary;
use App\Pictures\SizedPicture;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;

/**
 * Swaps each Picture and Mermaid block in a parsed Article for its sized image, and records on
 * the document (`picture_problems`) each one it can't show, with its line in the body.
 */
final class PlacePictures
{
    public const PROBLEMS = 'picture_problems';

    private const PREFIX = 'picture:';

    public function __construct(private readonly ?PictureLibrary $pictures) {}

    public function __invoke(DocumentParsedEvent $event): void
    {
        $document = $event->getDocument();
        $images = [];
        $diagrams = [];
        foreach ($document->iterator() as $node) {
            if ($node instanceof Image) {
                $images[] = $node;
            } elseif ($node instanceof FencedCode && strtolower($node->getInfoWords()[0] ?? '') === 'mermaid') {
                $diagrams[] = $node;
            }
        }

        $problems = [];
        foreach ($images as $image) {
            $problem = $this->placePicture($image);
            if ($problem !== null) {
                $problems[] = ['field' => 'Picture', 'line' => self::line($image), 'message' => $problem];
            }
        }

        if ($diagrams !== []) {
            $drawn = $this->pictures?->diagrams(array_map(fn (FencedCode $code): string => $code->getLiteral(), $diagrams))
                ?? array_fill(0, count($diagrams), 'can\'t be drawn: the Renderer was given no pictures folder');
            foreach ($diagrams as $index => $code) {
                if ($drawn[$index] instanceof SizedPicture) {
                    $code->replaceWith(new Diagram($drawn[$index]));
                } else {
                    $problems[] = ['field' => 'diagram', 'line' => self::line($code), 'message' => $drawn[$index]];
                }
            }
        }

        $document->data->set(self::PROBLEMS, $problems);
    }

    /** Puts the Picture in place of its image, or says why it can't. */
    private function placePicture(Image $image): ?string
    {
        $address = $image->getUrl();
        if (! str_starts_with($address, self::PREFIX)) {
            return 'show a Picture by its Picture Name, as ![](picture:<Picture Name>); "'.$address.'" isn\'t one';
        }
        $name = substr($address, strlen(self::PREFIX));
        if (self::words($image) !== '') {
            return "write the alt text in the Recipe {$name}.yaml, not the Article: ![](picture:{$name})";
        }
        if ($this->pictures === null) {
            return 'can\'t be shown: the Renderer was given no pictures folder';
        }

        $picture = $this->pictures->picture($name);
        if (is_string($picture)) {
            return $picture;
        }
        $image->replaceWith(new Picture($picture));

        return null;
    }

    /** The line of the body a node starts on, from its nearest block. */
    private static function line(Node $node): int
    {
        for ($at = $node; $at !== null && ! $at instanceof Document; $at = $at->parent()) {
            if ($at instanceof AbstractBlock && $at->getStartLine() !== null) {
                return $at->getStartLine();
            }
        }

        return 1;
    }

    private static function words(Image $image): string
    {
        $words = '';
        foreach ($image->iterator() as $node) {
            if ($node instanceof Text) {
                $words .= $node->getLiteral();
            }
        }

        return trim($words);
    }
}
