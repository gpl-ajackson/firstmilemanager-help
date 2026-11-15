<?php

namespace App\Content;

/** Where a Getting-started Article sits in the Getting-started Path: "Step 3 of 11". */
final readonly class PlaceInPath
{
    public function __construct(
        public int $step,
        public int $of,
        /** The next step, or null on the last one. */
        public ?Article $next,
    ) {}
}
