<?php

namespace App\Pictures;

use Stringable;

/**
 * A Recipe the tool couldn't follow, with the step that failed as the Recipe writes it and
 * what the screen showed instead. Its Picture is left as it was.
 */
final readonly class BrokenRecipe implements Stringable
{
    /**
     * @param  string  $at  where it broke: "signs_in_as", "starts_on", "step 3", "keep" or "outline"; for a
     *                      Client-tier Recipe also "team name" (renaming the team "Your 3PL"),
     *                      "top bar" (no top bar to leave out) or "text check" (ClientTier)
     * @param  string  $said  that part of the Recipe as written, such as "select: New Location", or the rule
     * @param  string|null  $screen  a picture of the screen where it broke, for its writer
     */
    public function __construct(
        public Recipe $recipe,
        public string $at,
        public string $said,
        public string $reason,
        public ?string $screen = null,
    ) {}

    public function __toString(): string
    {
        return "{$this->recipe->file}: {$this->at} ({$this->said}): {$this->reason}";
    }
}
