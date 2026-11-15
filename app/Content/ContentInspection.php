<?php

namespace App\Content;

use App\Renderer\Links\LinkTargets;

/**
 * What a rebuild of a content folder would find, read once without changing anything
 * (ContentLibrary::inspect()), for the Launch Checks to share.
 */
final readonly class ContentInspection
{
    /**
     * @param  list<ContentProblem>  $problems  Everything a rebuild would refuse (check 1).
     * @param  list<BrokenLink>  $brokenLinks  Links by name that lead nowhere (check 3); empty when the folder can't be read at all.
     * @param  ?LinkTargets  $linkTargets  What links by name may lead to (checks 2 and 10); null when the folder can't be read at all.
     */
    public function __construct(
        public array $problems,
        public array $brokenLinks,
        public ?LinkTargets $linkTargets,
    ) {}
}
