<?php

namespace App\Pictures;

/**
 * What one Picture Check found: the Pictures their Recipes no longer give, those they still
 * give, and the Recipes that couldn't be followed, from Stockist at one commit.
 */
final readonly class CheckedPictures
{
    /**
     * @param  list<ChangedPicture>  $changed
     * @param  list<string>  $same  Picture Names
     * @param  list<BrokenRecipe>  $broken  each screen where it broke, in the artefacts folder
     */
    public function __construct(
        public string $stockistCommit,
        public array $changed,
        public array $same,
        public array $broken,
    ) {}

    /** Whether every Picture is what its Recipe takes and every Recipe could be followed. */
    public function passed(): bool
    {
        return $this->changed === [] && $this->broken === [];
    }
}
