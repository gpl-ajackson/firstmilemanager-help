<?php

namespace App\Site\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cloudflare keeps pictures, fonts and styles for a year but never a page (spec #30, HTTP rules;
 * ADR-0004): Page View Counts are counted on the server, so a view Cloudflare answered would
 * never be counted, and a deploy must be seen at once. Every response that isn't a fingerprinted
 * file (App\Site\Asset::cacheForAYear) says so, pages not found, redirects and notices included:
 * Cache-Control for browsers and any cache, and CDN-Cache-Control, which Cloudflare reads first.
 */
final class PagesNotCached
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        assert($response instanceof Response);

        if (! $response->headers->hasCacheControlDirective('public')) {
            $response->headers->set('Cache-Control', 'no-cache, private');
            $response->headers->set('CDN-Cache-Control', 'no-store');
        }

        return $response;
    }
}
