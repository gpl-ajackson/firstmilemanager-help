<?php

namespace App\Site\Http\Controllers;

use App\Search\SearchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /searches/<Search id>/opened/<place>/<result address>: a result link on a settled Search's
 * results page (spec #30, "Search"). It marks that Search Opened with the result's place, then
 * sends the reader on to the result. The browser keeps the link's #fragment, so the Article
 * opens at its Section; nothing is ever put on the Article's own address.
 */
final class OpenSearchResult
{
    public function __invoke(Request $request, SearchService $search, string $search_id, string $place, string $to): RedirectResponse
    {
        $search->opened($search_id, (int) $place, "/{$to}", $request->userAgent());

        return redirect("/{$to}")->header('X-Robots-Tag', 'noindex');
    }
}
