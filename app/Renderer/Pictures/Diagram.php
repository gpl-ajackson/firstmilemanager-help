<?php

namespace App\Renderer\Pictures;

use App\Pictures\SizedPicture;
use League\CommonMark\Node\Block\AbstractBlock;

/** A Mermaid diagram, drawn as an image during the rebuild, where its text was. */
final class Diagram extends AbstractBlock
{
    public function __construct(public readonly SizedPicture $picture)
    {
        parent::__construct();
    }
}
