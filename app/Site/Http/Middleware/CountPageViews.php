<?php

namespace App\Site\Http\Middleware;

use App\Records\Records;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Page View Counts (spec #30): every successful page view, counted on the server, so no script
 * on the page and nothing on the reader's device. A page view is a GET answered 200 with a
 * page (HTML); redirects, page not found, a Retired Article's notice and plain-text files such
 * as robots.txt are not page views. The page is its path, never its query string.
 */
final class CountPageViews
{
    public function __construct(private readonly Records $records) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        assert($response instanceof Response);

        if ($request->isMethod('GET')
            && $response->getStatusCode() === Response::HTTP_OK
            && str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            $this->records->countPageView('/'.ltrim($request->path(), '/'), $request->userAgent());
        }

        return $response;
    }
}
