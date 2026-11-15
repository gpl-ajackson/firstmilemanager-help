<?php

namespace App\Site\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The marketing site's security headers (firstmilemanager-marketing public/_headers), on every
 * response, page not found included.
 */
final class SecurityHeaders
{
    public const HEADERS = [
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Frame-Options' => 'DENY',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        assert($response instanceof Response);

        $response->headers->add(self::HEADERS);

        return $response;
    }
}
