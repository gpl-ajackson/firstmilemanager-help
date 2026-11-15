<?php

namespace App\Records;

/**
 * Obvious bots, left out of every count: search engine crawlers, link previewers, uptime and
 * speed checkers, headless browsers and command-line tools, told by their user agent. A bot
 * that pretends to be a browser is counted; the counts are figures, not proof.
 */
final class Bots
{
    private const PATTERN = '/bot|crawl|spider|slurp|archiver|preview|fetcher|scan|monitor|uptime|health|pingdom|lighthouse|pagespeed|headless|phantomjs|facebookexternalhit|curl|wget|httpie|python|java\/|okhttp|go-http-client|node-fetch|axios|guzzle|libwww|httpclient|scrapy|feed|^mozilla\/\d\.\d$/i';

    public static function isObvious(?string $userAgent): bool
    {
        $userAgent = trim((string) $userAgent);

        return $userAgent === '' || preg_match(self::PATTERN, $userAgent) === 1;
    }
}
