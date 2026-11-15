<?php

namespace App\Site\Http;

use App\Search\GlossaryAnswer;
use App\Search\Result;
use App\Search\Results;

/**
 * Results as the search box's script (#46) reads them, from the suggestions address and the
 * settle address alike, so it draws both with one piece of code.
 */
final class ResultsJson
{
    /**
     * Each result: its place, title, link (`address`: the "opened" address once the Search is
     * settled, else where it opens), where it opens (`opens_at`: the Article, with any
     * #Section), the matched Section's heading (`section`), Article Type, Product Area and
     * whether it is for clients.
     *
     * @return list<array{place: int, title: string, address: string, opens_at: string, section: ?string, type: string, product_area: string, for_clients: bool}>
     */
    public static function results(Results $results): array
    {
        return array_map(fn (Result $result): array => [
            'place' => $result->place,
            'title' => $result->title,
            'address' => $result->address,
            'opens_at' => $result->articleAddress,
            'section' => $result->sectionHeading,
            'type' => $result->type->value,
            'product_area' => $result->productArea,
            'for_clients' => $result->isForClients(),
        ], $results->results);
    }

    /**
     * Each Glossary answer, shown above the results: its place, term, the Other Name typed
     * (`also_called`, or null), link (`address`), the entry's own address (`opens_at`) and
     * whether it is in the Client Glossary.
     *
     * @return list<array{place: int, term: string, also_called: ?string, address: string, opens_at: string, for_clients: bool}>
     */
    public static function glossaryAnswers(Results $results): array
    {
        return array_map(fn (GlossaryAnswer $answer): array => [
            'place' => $answer->place,
            'term' => $answer->term,
            'also_called' => $answer->alsoCalled,
            'address' => $answer->address,
            'opens_at' => $answer->entryAddress,
            'for_clients' => $answer->isForClients(),
        ], $results->glossaryAnswers);
    }
}
