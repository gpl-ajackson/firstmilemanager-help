<?php

namespace App\LaunchChecks\Links;

use App\LaunchChecks\Check;
use App\LaunchChecks\CheckContext;
use App\LaunchChecks\Findings;
use App\Pictures\PictureLibrary;
use App\Renderer\Links\LinksByName;

/**
 * Launch Check 3, Links: every link by name and Section Name resolves for its page's readers
 * (the rebuild's list of links that lead nowhere, which a rebuild only prints, fails here),
 * Client-tier pages link only to Client-tier pages, and no page but a Joint-only Article names
 * Stockist (ADR-0006).
 */
final readonly class LinksCheck implements Check
{
    public function __construct(private PictureLibrary $pictures) {}

    public function run(CheckContext $context, Findings $findings): void
    {
        foreach ($context->content()->brokenLinks as $link) {
            $findings->fail(
                LinksByName::isOutOfTier($link->problem) ? 'links.client-tier' : 'links.resolves',
                $link->file,
                $link->line,
                "{$link->field} \"{$link->written}\": {$link->problem}",
            );
        }

        $pictures = $context->picturesPath === null ? $this->pictures : $this->pictures->from($context->picturesPath);
        foreach ($context->articles() as $article) {
            if (($article->header['audience_tier'] ?? null) === 'Client') {
                StockistOnClientPages::check($article, $findings, $pictures);
            } elseif (($article->header['joint_only'] ?? false) !== true) {
                StockistOnClientPages::check($article, $findings, $pictures, StockistOnClientPages::FOR_FMM_ONLY);
            }
        }

        foreach ($context->glossaryEntries() as $entry) {
            StockistOnClientPages::checkEntry($entry, $findings, $pictures,
                str_starts_with($entry->file, 'client-glossary/') ? StockistOnClientPages::FOR_CLIENTS : StockistOnClientPages::FOR_FMM_ONLY);
        }
    }
}
