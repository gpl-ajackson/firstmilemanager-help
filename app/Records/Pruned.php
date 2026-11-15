<?php

namespace App\Records;

/** What one run of Records::prune() did. */
final readonly class Pruned
{
    /**
     * @param  list<string>  $monthsTotalled  The months added up first (Y-m).
     */
    public function __construct(
        public array $monthsTotalled,
        public int $searchRecords,
        public int $votes,
        public int $comments,
    ) {}
}
