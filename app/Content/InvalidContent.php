<?php

namespace App\Content;

use RuntimeException;

/** The content folder can't be published; the database copy is left as it was. */
final class InvalidContent extends RuntimeException
{
    /**
     * @param  non-empty-list<ContentProblem>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(count($problems).' '.str('problem')->plural(count($problems)).' in the content folder:'
            .PHP_EOL.implode(PHP_EOL, array_map(strval(...), $problems)));
    }
}
