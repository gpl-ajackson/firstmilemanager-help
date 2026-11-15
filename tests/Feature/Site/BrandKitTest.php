<?php

use App\Site\Asset;

/*
 * The help centre wears this repository's copy of First Mile Manager's brand folder (brand/, from
 * the marketing repository), served from public/ without a build step. When brand/ changes, copy
 * the changed files across.
 */

it('serves the brand kit\'s files unchanged', function (string $brandFile, string $publicFile) {
    expect(file_exists(public_path($publicFile)))->toBeTrue("public/{$publicFile} is missing: copy brand/{$brandFile} there")
        ->and(md5_file(public_path($publicFile)))->toBe(md5_file(base_path("brand/{$brandFile}")), "public/{$publicFile} differs from brand/{$brandFile}: copy it across");
})->with(function () {
    $fonts = array_map(fn (string $path): string => basename($path), glob(dirname(__DIR__, 3).'/brand/fonts/*') ?: []);

    return [
        'colours' => ['tokens.css', 'css/brand.css'],
        'light lock-up' => ['logo/fmm-lockup-light.svg', 'images/fmm-lockup-light.svg'],
        'dark lock-up' => ['logo/fmm-lockup-dark.svg', 'images/fmm-lockup-dark.svg'],
        'favicon' => ['icons/favicon.ico', 'favicon.ico'],
        'favicon (SVG)' => ['icons/favicon.svg', 'favicon.svg'],
        'touch icon' => ['icons/apple-touch-icon.png', 'apple-touch-icon.png'],
        ...array_combine($fonts, array_map(fn (string $font): array => ["fonts/{$font}", "fonts/{$font}"], $fonts)),
    ];
});

it('links the brand\'s styles and logo from every page', function () {
    content()->article('receive-at-the-dock')->publish();

    $this->get('/articles/receive-at-the-dock')
        ->assertSee('href="/assets/'.Asset::fingerprint('css/brand.css').'/css/brand.css"', escape: false)
        ->assertSee('href="/assets/'.Asset::fingerprint('css/tokens.css').'/css/tokens.css"', escape: false)
        ->assertSee('href="/assets/'.Asset::fingerprint('css/site.css').'/css/site.css"', escape: false)
        ->assertSee('alt="First Mile Manager"', escape: false);
});

it('builds the theme on the brand\'s one accent, Route teal, with ink labels on it', function () {
    $theme = (string) file_get_contents(public_path('css/tokens.css'));

    expect($theme)->toContain('--hc-accent: var(--fmm-route-teal);')
        ->and($theme)->toContain('--hc-on-accent: var(--fmm-ink);')
        ->and($theme)->toContain('--hc-link: var(--fmm-route-teal-text);')
        ->and($theme)->not->toContain('Barlow');
});
