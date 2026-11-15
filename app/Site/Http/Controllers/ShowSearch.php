<?php

namespace App\Site\Http\Controllers;

use App\Content\ContentLibrary;
use App\Search\Scope;
use App\Search\SearchService;
use App\Search\SearchUnavailable;
use App\Site\Http\SearchLimit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * /search?q=: the results page (spec #30, "Search"). A plain GET form and page that work
 * without script, searching Stockist's centre through the search service. Sending the form
 * settles the Search, so it is recorded (once going public is on) and its result links go
 * through the "opened" address. `&search=<id>` names a Search the script has already settled
 * with these words (#46), so it isn't recorded twice. Never indexed, even once public.
 */
final class ShowSearch
{
    public function __invoke(Request $request, SearchService $search, ContentLibrary $library): Response
    {
        $words = $request->query('q');
        $words = is_string($words) ? $words : '';

        $status = 200;
        try {
            $settled = $request->query('search');
            // Past the reader's limit the Search is shown, not recorded (SearchLimit).
            $results = trim($words) === '' || SearchLimit::allows($request)
                ? $search->settle($words, Scope::helpCentre(), $request->userAgent(), is_string($settled) ? $settled : null)
                : $search->search($words, Scope::helpCentre());
        } catch (SearchUnavailable $unavailable) {
            report($unavailable);
            $results = null;
            $status = 503;
        }

        return response()
            ->view('site.search', [
                'words' => trim($words),
                'results' => $results,
                'productAreas' => $library->shownProductAreas(),
            ], $status)
            ->header('X-Robots-Tag', 'noindex');
    }
}
