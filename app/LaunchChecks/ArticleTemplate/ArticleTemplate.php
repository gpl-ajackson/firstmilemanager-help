<?php

namespace App\LaunchChecks\ArticleTemplate;

/** The Article Template for one Article Type: its parts in page order and its guide length. */
final readonly class ArticleTemplate
{
    /**
     * @param  list<TemplatePart>  $parts
     */
    public function __construct(
        public string $type,
        public string $file,
        public array $parts,
        public ?int $guideWords,
    ) {}
}
