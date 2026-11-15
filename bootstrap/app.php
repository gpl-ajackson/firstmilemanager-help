<?php

use App\Site\Http\Middleware\CountPageViews;
use App\Site\Http\Middleware\NoindexUntilPublic;
use App\Site\Http\Middleware\PagesNotCached;
use App\Site\Http\Middleware\SameOrigin;
use App\Site\Http\Middleware\SecurityHeaders;
use App\Site\Http\RedirectOldAddresses;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            Route::middleware('public')->group(__DIR__.'/../routes/public.php');
        },
    )
    // Each module keeps its artisan commands in its own Console folder (app/<Module>/Console).
    ->withCommands(glob(__DIR__.'/../app/*/Console', GLOB_ONLYDIR) ?: [])
    ->withMiddleware(function (Middleware $middleware): void {
        // Public pages: no session, no cookie encryption, no CSRF, so no cookie is ever set.
        // Each successful page view adds one to its page's count for the day (Records).
        $middleware->group('public', [
            SubstituteBindings::class,
            CountPageViews::class,
        ]);

        // Readers come through Cloudflare (config/trustedproxy.php lists its addresses), so the
        // reader's own address and scheme are taken from X-Forwarded-For and X-Forwarded-Proto,
        // but only on a request from Cloudflare. The other X-Forwarded headers are never trusted.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

        // The search box's words arrive as typed: a space after the last word says the reader
        // has finished it, so suggestions (#46) stop treating it as the start of a longer one.
        // The search service trims them itself.
        $middleware->trimStrings(except: ['q']);

        // A post from another site is refused before it can count towards a rate limit.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: SameOrigin::class);

        // Every response, page not found included.
        $middleware->append([
            SecurityHeaders::class,
            NoindexUntilPublic::class,
            PagesNotCached::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // An old address with no page of its own redirects for good to where its help is now.
        $exceptions->render(new RedirectOldAddresses);
    })->create();
