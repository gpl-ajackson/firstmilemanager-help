<?php

namespace App\Site\Http\Controllers;

use App\Site\GoingPublic;
use Illuminate\Http\Response;

/**
 * /robots.txt: disallows everything until the help centre goes public; then lets search engines
 * in and names the sitemap. The addresses that record something or only send the reader on
 * (spec #30, Addresses) stay closed to crawlers. The search results page stays open to them on
 * purpose, so that they read its "noindex": a page robots.txt closes can still be indexed from
 * links to it.
 */
final class RobotsTxt
{
    /** Never pages: a settled Search's "opened" address, suggestions, votes and "Still stuck?". */
    public const CLOSED = ['/searches/', '/suggestions', '/feedback/', '/still-stuck/'];

    public function __invoke(): Response
    {
        $lines = ['User-agent: *'];
        if (GoingPublic::isOn()) {
            foreach (self::CLOSED as $address) {
                $lines[] = 'Disallow: '.$address;
            }
            $lines[] = '';
            $lines[] = 'Sitemap: '.rtrim(config()->string('help.address'), '/').'/sitemap.xml';
        } else {
            $lines[] = 'Disallow: /';
        }

        return response(implode("\n", $lines)."\n")->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
