<?php

namespace App\Renderer\Pictures;

use App\Pictures\SizedPicture;
use League\CommonMark\Node\Inline\AbstractInline;

/** A Picture where the writer put it, under its step, sized for the page. */
final class Picture extends AbstractInline
{
    public function __construct(public readonly SizedPicture $picture)
    {
        parent::__construct();
    }
}
