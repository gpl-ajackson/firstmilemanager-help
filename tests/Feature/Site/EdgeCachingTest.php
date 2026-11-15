<?php

use Tests\Support\ReaderPage;

/*
 * Spec #30, HTTP rules and ADR-0004: Cloudflare caches pictures, fonts and styles for a year but
 * never pages, because Page View Counts are counted on the server and a cached view never
 * reaches it. So every response that isn't a fingerprinted file tells Cloudflare not to keep it
 * (Cache-Control no-cache, private; and CDN-Cache-Control no-store, which Cloudflare reads before
 * Cache-Control), pages not found, redirects and retired notices included, so a deploy is seen
 * at once.
 */

const NOT_CACHED = 'no-cache, private';
const CACHED_FOR_A_YEAR = 'immutable, max-age=31536000, public';

beforeEach(function () {
    content()
        ->picture('inbound-shipments-open-a-shipment', ['device' => 'desk'], height: 1600)
        ->article('receive-at-the-dock', ['former_addresses' => ['/articles/receive-a-shipment']], <<<'MD'
            ## Steps

            1. Open the shipment.

               ![](picture:inbound-shipments-open-a-shipment)

            2. Select **Complete receiving**.
            MD)
        ->article('count-stock', ['status' => 'Draft'])
        ->article('print-a-dock-sheet', ['status' => 'Retired', 'what_changed' => 'Stockist no longer prints dock sheets.'])
        ->glossaryEntry('zone')
        ->releaseNote('2026-11-02')
        ->publish();
});

it('tells Cloudflare not to keep any page, redirect or notice', function (string $address, int $status) {
    $this->get($address)
        ->assertStatus($status)
        ->assertHeader('Cache-Control', NOT_CACHED)
        ->assertHeader('CDN-Cache-Control', 'no-store');
})->with([
    'the home page' => ['/', 200],
    'an Article' => ['/articles/receive-at-the-dock', 200],
    'a listing' => ['/getting-started', 200],
    'a Glossary Entry' => ['/glossary/zone', 200],
    'a Release Note' => ['/whats-new/2026-11-02', 200],
    'search results' => ['/search?q=dock', 200],
    'suggestions' => ['/suggestions?q=dock', 200],
    'robots.txt' => ['/robots.txt', 200],
    'the sitemap' => ['/sitemap.xml', 200],
    'llms.txt' => ['/llms.txt', 200],
    'a former address' => ['/articles/receive-a-shipment', 301],
    'the privacy notice link' => ['/privacy', 302],
    'a Retired Article\'s notice' => ['/articles/print-a-dock-sheet', 410],
    'a Draft Article' => ['/articles/count-stock', 404],
    'an address with no page' => ['/no/such/page', 404],
    'a file at an old fingerprint' => ['/assets/0123456789abcdef/css/site.css', 404],
]);

it('lets Cloudflare keep styles, fonts, logos and Pictures for a year', function () {
    $page = ReaderPage::of($this->get('/articles/receive-at-the-dock')->assertOk());
    $files = [
        ...array_column($page->attributes('link[rel="stylesheet"], link[rel="preload"]'), 'href'),
        ...array_column($page->attributes('.site-head img'), 'src'),
        ...array_column($page->attributes('script[src]'), 'src'),
        ...array_column($page->attributes('img.picture-desk'), 'src'),
    ];

    expect(array_filter($files, fn (string $file): bool => str_starts_with($file, '/pictures/')))->not->toBeEmpty()
        ->and(array_filter($files, fn (string $file): bool => str_starts_with($file, '/assets/')))->not->toBeEmpty();
    foreach ($files as $file) {
        $response = $this->get($file)->assertOk()->assertHeader('Cache-Control', CACHED_FOR_A_YEAR);
        expect($response->headers->has('CDN-Cache-Control'))->toBeFalse("{$file} should be left to its Cache-Control");
    }
});
