<?php

namespace Tests\Support;

use Closure;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Breaks chosen pages of the help centre for a test, as a careless change to a layout or a
 * script would: one sets a cookie, one writes local storage with an inline script, one has a
 * part too wide for a handheld, one keeps a Search as typed with the reader's browser.
 * Middleware in the public group, so it acts on the page as the app serves it to a browser;
 * content alone can't do this (raw HTML in Markdown is escaped).
 *
 *     FaultyPages::make(['/articles/x' => FaultyPages::COOKIE]);
 */
final readonly class FaultyPages
{
    public const COOKIE = 'cookie';

    public const LOCAL_STORAGE = 'local storage';

    public const TOO_WIDE = 'too wide';

    /** A Search Record written as typed, with the reader's browser: what Cleaning and Records prevent. */
    public const KEEPS_THE_READER = 'keeps the reader';

    /** @param  array<string, string>  $faults  Page path => fault. */
    public function __construct(private array $faults) {}

    /** @param  array<string, string>  $faults  Page path => fault. */
    public static function make(array $faults): void
    {
        app()->instance(self::class, new self($faults));
        $kernel = app(Kernel::class);
        assert($kernel instanceof HttpKernel);
        $kernel->appendMiddlewareToGroup('public', self::class);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        assert($response instanceof Response);

        $fault = $this->faults['/'.ltrim($request->path(), '/')] ?? null;
        if ($fault === self::COOKIE) {
            $response->headers->setCookie(Cookie::create('seen', 'yes'));
        } elseif ($fault === self::LOCAL_STORAGE) {
            $response->setContent(str_replace('</body>', "<script>localStorage.setItem('seen', 'yes');</script></body>", (string) $response->getContent()));
        } elseif ($fault === self::KEEPS_THE_READER && $request->query('q') !== null) {
            DB::table('search_records')->insert([
                'id' => (string) Str::uuid(), 'words' => $request->query('q').' '.$request->userAgent(), 'results_shown' => 0,
                'glossary_entry_shown' => false, 'outcome' => 'No results', 'day' => now('Europe/London')->toDateString(), 'where_made' => 'help centre',
            ]);
        } elseif ($fault === self::TOO_WIDE) {
            $response->setContent(preg_replace('/(<main[^>]*>)/', '$1<div class="banner" style="width: 400px; height: 10px"></div>', (string) $response->getContent(), 1));
        }

        return $response;
    }
}
