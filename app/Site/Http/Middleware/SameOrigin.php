<?php

namespace App\Site\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a form post through only when it comes from one of the help centre's own pages. Public
 * pages have no session, so no CSRF token (spec #30, HTTP rules); the browser's own word on where
 * the post came from stands in for it. Sec-Fetch-Site, when sent, must say same-origin; otherwise
 * Origin must be the help centre's. A post with neither is refused, as is any other: browsers
 * send one or both with every form post.
 */
final class SameOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isSameOrigin($request)) {
            return response()
                ->view('site.not-from-here', [], Response::HTTP_FORBIDDEN)
                ->header('X-Robots-Tag', 'noindex');
        }

        $response = $next($request);
        assert($response instanceof Response);

        return $response;
    }

    private function isSameOrigin(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');
        if ($site !== null) {
            return $site === 'same-origin';
        }

        $origin = $request->headers->get('Origin');

        return $origin !== null && in_array(rtrim($origin, '/'), $this->ownOrigins($request), true);
    }

    /**
     * The help centre's origin as this request reached it, and as the app's address names it.
     *
     * @return list<string>
     */
    private function ownOrigins(Request $request): array
    {
        $origins = [$request->getSchemeAndHttpHost()];
        $url = parse_url(config()->string('app.url', ''));
        if (isset($url['scheme'], $url['host'])) {
            $origins[] = $url['scheme'].'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '');
        }

        return $origins;
    }
}
