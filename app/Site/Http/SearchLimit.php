<?php

namespace App\Site\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * How many Searches one reader may have recorded in a few minutes, so a loop or a crawler that
 * looks like a browser can't swamp the Search Records and the Help Gaps Report drawn from them.
 * Past the limit the reader still searches and sees every result as usual; the Search just
 * isn't recorded, and its results lead straight to their pages.
 *
 * Like the votes limit (ArticleFeedback::limit()), the key is a keyed hash of the reader's IP
 * address, held only by the rate limiter's cache store (config cache.limiter, never the
 * database) for those minutes, and never put in a record or a log (spec #30, Further Note 4).
 */
final class SearchLimit
{
    /**
     * Searches recorded per reader in SEARCH_MINUTES: far more than a person makes (each pause
     * in typing with new words settles one), while a loop is cut to six a minute.
     */
    public const SEARCHES_ALLOWED = 60;

    public const SEARCH_MINUTES = 10;

    /** Whether this reader's next Search may be recorded, counting it when it may. */
    public static function allows(Request $request): bool
    {
        $key = 'searches:'.hash_hmac('sha256', (string) $request->ip(), config()->string('app.key', ''));
        if (RateLimiter::tooManyAttempts($key, self::SEARCHES_ALLOWED)) {
            return false;
        }
        RateLimiter::hit($key, self::SEARCH_MINUTES * 60);

        return true;
    }
}
