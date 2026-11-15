<?php

namespace App\Pictures;

/** A Picture the tool took by following its Recipe to the end. */
final readonly class TakenPicture
{
    /**
     * @param  list<string>  $shows  the texts the Picture shows, read for a Client-tier Picture's
     *                               text check (ClientTier); empty for staff Pictures, which aren't read
     */
    public function __construct(
        public Recipe $recipe,
        public string $path,
        public int $width,
        public int $height,
        public array $shows = [],
    ) {}
}
