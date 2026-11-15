<?php

use App\Site\Asset;

/*
 * The help centre wears this repository's copy of the Bay brand kit (brand/), served from
 * public/ without a build step. When brand/ changes, copy the changed files across.
 */

it('serves the brand kit\'s files unchanged', function (string $brandFile, string $publicFile) {
    expect(file_exists(public_path($publicFile)))->toBeTrue("public/{$publicFile} is missing: copy brand/{$brandFile} there")
        ->and(md5_file(public_path($publicFile)))->toBe(md5_file(base_path("brand/{$brandFile}")), "public/{$publicFile} differs from brand/{$brandFile}: copy it across");
})->with(function () {
    $fonts = array_map(fn (string $path): string => basename($path), glob(dirname(__DIR__, 3).'/brand/fonts/*') ?: []);

    return [
        'tokens' => ['tokens.css', 'css/tokens.css'],
        'light lock-up' => ['logos/stockist-wms-lockup-light.svg', 'images/stockist-wms-lockup-light.svg'],
        'dark lock-up' => ['logos/stockist-wms-lockup-dark.svg', 'images/stockist-wms-lockup-dark.svg'],
        'favicon' => ['icons/favicon.ico', 'favicon.ico'],
        'favicon (SVG)' => ['icons/favicon.svg', 'favicon.svg'],
        'touch icon' => ['icons/apple-touch-icon.png', 'apple-touch-icon.png'],
        ...array_combine($fonts, array_map(fn (string $font): array => ["fonts/{$font}", "fonts/{$font}"], $fonts)),
    ];
});

it('links the brand\'s styles and logo from every page', function () {
    content()->article('receive-at-the-dock')->publish();

    $this->get('/articles/receive-at-the-dock')
        ->assertSee('href="/assets/'.Asset::fingerprint('css/tokens.css').'/css/tokens.css"', escape: false)
        ->assertSee('href="/assets/'.Asset::fingerprint('css/site.css').'/css/site.css"', escape: false)
        ->assertSee('alt="Stockist WMS"', escape: false);
});
