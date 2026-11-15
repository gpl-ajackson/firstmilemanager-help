<?php

namespace App\Site\Http\Controllers;

use App\Search\Scope;
use App\Search\SearchService;
use App\Search\SearchUnavailable;
use App\Site\Http\ResultsJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /suggestions?q=<words>: what the search box's script (#46) shows as the reader types.
 * Never recorded: a Search is recorded only once it settles (POST /searches, or the form sent
 * to /search). Never indexed.
 *
 * Answers {"words": "<words searched for>", "glossary_answers": [...], "suggestions": [...]}
 * (ResultsJson; Glossary answers take the first places), with none for blank words, and 503
 * with none when search isn't working.
 */
final class ShowSuggestions
{
    public function __invoke(Request $request, SearchService $search): JsonResponse
    {
        $words = $request->query('q');
        $words = is_string($words) ? $words : '';

        try {
            $suggestions = $search->suggest($words, Scope::helpCentre());
        } catch (SearchUnavailable $unavailable) {
            report($unavailable);

            return response()->json(['words' => trim($words), 'glossary_answers' => [], 'suggestions' => []], 503)->header('X-Robots-Tag', 'noindex');
        }

        return response()->json([
            'words' => $suggestions->words,
            'glossary_answers' => ResultsJson::glossaryAnswers($suggestions),
            'suggestions' => ResultsJson::results($suggestions),
        ])->header('X-Robots-Tag', 'noindex');
    }
}
