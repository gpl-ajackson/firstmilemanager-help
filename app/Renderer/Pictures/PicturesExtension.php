<?php

namespace App\Renderer\Pictures;

use App\Pictures\PictureLibrary;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\ExtensionInterface;

/**
 * Pictures and diagrams in an Article's body (spec #30, Renderer; ADR-0005), kept apart from
 * the rest of the Renderer so other steps (links by name, #38) sit beside it:
 *
 * - `![](picture:<Picture Name>)` on its own line under a step shows that Picture from the
 *   pictures folder, with the alt text from its Picture Recipe. Nothing else may be an image.
 * - A ```mermaid block is drawn as an image during the rebuild, its alt text taken from its
 *   `accDescr:` line, so no diagram script reaches the reader.
 *
 * What it can't show is kept on the document as problems that fail the rebuild.
 */
final class PicturesExtension implements ExtensionInterface
{
    public function __construct(private readonly ?PictureLibrary $pictures) {}

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addEventListener(DocumentParsedEvent::class, new PlacePictures($this->pictures));
        $environment->addRenderer(Picture::class, new PictureRenderer);
        $environment->addRenderer(Diagram::class, new PictureRenderer);
    }
}
