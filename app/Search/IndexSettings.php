<?php

namespace App\Search;

/**
 * @internal The settings of the one index (spec #30, "Search"), which holds Published Articles
 * (ArticleDocument) and Glossary Entries (GlossaryDocument) of both tiers, told apart by "kind".
 */
final class IndexSettings
{
    /** What a search for Articles looks in: title, then summary and Section headings, then body. */
    public const ARTICLE_ATTRIBUTES = ['title', 'lead', 'body'];

    /** What a search for Glossary Entries looks in: the term and every name it is also called. */
    public const GLOSSARY_ATTRIBUTES = ['names'];

    /**
     * @param  array<string, list<string>>  $synonyms  Each Other Name with the terms it maps to, one way.
     * @return array<string, mixed>
     */
    public static function with(array $synonyms): array
    {
        return [
            'searchableAttributes' => [...self::ARTICLE_ATTRIBUTES, ...self::GLOSSARY_ATTRIBUTES],
            'displayedAttributes' => [
                'id', 'kind', 'name', 'audience_tier',
                // Articles
                'title', 'summary', 'type', 'product_area', 'lead', 'lead_sections', 'message_patterns',
                // Glossary Entries
                'term', 'term_names', 'also_called', 'address', 'definition_html',
            ],
            'filterableAttributes' => ['kind', 'audience_tier', 'has_messages'],
            'sortableAttributes' => ['tier_rank'],
            // The scope's sort (Client tier below staff on Stockist's centre) comes before
            // relevance; then attribute rank before proximity, so a title match beats the same
            // words close together in the body.
            'rankingRules' => ['sort', 'words', 'typo', 'attributeRank', 'proximity', 'wordPosition', 'exactness'],
            // Typos only in words of five letters or more: "palet" finds pallet, "PO" and "ASN" match exactly.
            'typoTolerance' => [
                'enabled' => true,
                'minWordSizeForTypos' => ['oneTypo' => 5, 'twoTypos' => 9],
            ],
            // Prefixes are indexed for suggestions (#46), which let the last word still being
            // typed match the start of longer words. SearchService turns them off for every
            // other search, so on the results page "PO" never finds "position".
            'prefixSearch' => 'indexingTime',
            // One way only: "bin" finds what "location" finds, never the other way round.
            'synonyms' => $synonyms === [] ? (object) [] : $synonyms,
        ];
    }
}
