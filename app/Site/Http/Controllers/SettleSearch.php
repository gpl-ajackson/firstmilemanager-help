<?php

namespace App\Site\Http\Controllers;

use App\Search\Scope;
use App\Search\SearchService;
use App\Search\SearchUnavailable;
use App\Site\Http\ResultsJson;
use App\Site\Http\SearchLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /searches (q=<words>): settles a Search for the search box's script (#46), when the
 * reader stops typing for two seconds with three letters or more, or opens a suggestion. The
 * words are searched as the suggestions showed them (SearchService::settleSuggestions()), so
 * the Search holds what the reader was shown. It is recorded once going public is on, and the
 * answer gives the Search's id and each result's "opened" address, for the script to put on
 * the suggestions it shows and in the form's `search` field, so pressing Enter afterwards
 * doesn't record the same Search again. Without script the form settles the Search itself
 * (/search), so the page never depends on this.
 *
 * Answers {"search": "<id>" | null, "glossary_answers": [...], "results": [...]} (ResultsJson,
 * each `address` the "opened" one; Glossary answers take the first places), 201 when a Search
 * was settled, 200 for blank words, 503 when search isn't working.
 */
final class SettleSearch
{
    public function __invoke(Request $request, SearchService $search): JsonResponse
    {
        $words = $request->input('q');
        $words = is_string($words) ? $words : '';

        try {
            // Past the reader's limit the suggestions are given back unsettled, not recorded (SearchLimit).
            $results = trim($words) === '' || SearchLimit::allows($request)
                ? $search->settleSuggestions($words, Scope::helpCentre(), $request->userAgent())
                : $search->suggest($words, Scope::helpCentre());
        } catch (SearchUnavailable $unavailable) {
            report($unavailable);

            return response()->json(['search' => null, 'glossary_answers' => [], 'results' => []], 503)->header('X-Robots-Tag', 'noindex');
        }

        return response()->json([
            'search' => $results->search,
            'glossary_answers' => ResultsJson::glossaryAnswers($results),
            'results' => ResultsJson::results($results),
        ], $results->search === null ? 200 : 201)->header('X-Robots-Tag', 'noindex');
    }
}
