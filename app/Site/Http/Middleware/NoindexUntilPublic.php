<?php

namespace App\Site\Http\Middleware;

use App\Site\GoingPublic;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Until the help centre goes public, every response tells search engines not to index it,
 * as well as the robots meta tag on every page and robots.txt disallowing all.
 */
final class NoindexUntilPublic
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        assert($response instanceof Response);

        if (! GoingPublic::isOn()) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }
}
