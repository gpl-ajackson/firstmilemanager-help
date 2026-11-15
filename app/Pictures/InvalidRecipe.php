<?php

namespace App\Pictures;

use RuntimeException;

/** A Picture Recipe the tool can't follow as written. */
final class InvalidRecipe extends RuntimeException
{
    /**
     * @param  non-empty-list<RecipeProblem>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode("\n", array_map(strval(...), $problems)));
    }
}
