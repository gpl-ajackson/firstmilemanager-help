<?php

namespace App\Site\Http\Controllers;

use App\Site\GoingPublic;
use App\Site\IndexedPages;
use Illuminate\Http\Response;

/**
 * /sitemap.xml: every page search engines may index (IndexedPages) at the help centre's own
 * address, once the help centre has gone public; until then an empty sitemap (spec #30, HTTP
 * rules). Itself never indexed.
 */
final class Sitemap
{
    public function __invoke(IndexedPages $pages): Response
    {
        $address = rtrim(config()->string('help.address'), '/');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach (GoingPublic::isOn() ? $pages->all() : [] as $page) {
            $xml .= '  <url><loc>'.htmlspecialchars($address.$page->address, ENT_XML1).'</loc>'
                .($page->lastChanged !== null ? '<lastmod>'.$page->lastChanged->toDateString().'</lastmod>' : '')
                ."</url>\n";
        }
        $xml .= "</urlset>\n";

        return response($xml)
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('X-Robots-Tag', 'noindex');
    }
}
