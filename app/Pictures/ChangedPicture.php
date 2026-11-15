<?php

namespace App\Pictures;

use Stringable;

/**
 * A Picture that isn't what its Recipe takes now: the Picture as it is in the pictures folder
 * (before), the new take (after) and the difference image, each a file in the Picture Check's
 * artefacts folder. A Recipe with no Picture yet has no before and no difference.
 */
final readonly class ChangedPicture implements Stringable
{
    public function __construct(
        public Recipe $recipe,
        public ?string $before,
        public string $after,
        public ?string $difference,
        public string $why,
    ) {}

    /** "warehouses-locations-list.png: 1,234 pixels differ" */
    public function __toString(): string
    {
        return "{$this->recipe->name}.png: {$this->why}";
    }
}
