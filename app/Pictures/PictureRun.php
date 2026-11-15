<?php

namespace App\Pictures;

/** What one run of the picture tool took, and which Recipes broke. */
final readonly class PictureRun
{
    /**
     * @param  list<TakenPicture>  $taken
     * @param  list<BrokenRecipe>  $broken
     */
    public function __construct(
        public string $stockistCommit,
        public array $taken,
        public array $broken,
    ) {}
}
