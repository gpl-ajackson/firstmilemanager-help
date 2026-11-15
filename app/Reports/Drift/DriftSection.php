<?php

namespace App\Reports\Drift;

/**
 * One kind of drift item in the Help Drift Report: its heading, each item as one line of
 * Markdown that links where it is, and what couldn't be checked (so an empty list never reads
 * as "None this month" when it wasn't looked at).
 */
final readonly class DriftSection
{
    /**
     * @param  list<string>  $items
     * @param  list<string>  $notChecked  why part or all of it wasn't checked
     */
    public function __construct(
        public string $heading,
        public array $items,
        public array $notChecked = [],
    ) {}
}
