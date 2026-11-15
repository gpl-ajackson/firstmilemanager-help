<?php

use App\Site\Asset;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\ReaderPage;
use Tests\TestCase;

/*
 * Spec #30, HTTP rules: pictures, fonts and styles are fingerprinted and cached for a year, so
 * Cloudflare and the reader's browser keep them, and a deploy that changes one changes its
 * address. Pages themselves are never cached that way (Page View Counts are counted on the
 * server). Picture addresses are tested in PicturesTest.
 */

const A_YEAR = 'immutable, max-age=31536000, public';

beforeEach(function () {
    content()->article('receive-at-the-dock')->publish();
});

it('links every style, font and logo at a fingerprinted address', function () {
    $page = ReaderPage::of($this->get('/articles/receive-at-the-dock'));
    $addresses = [
        ...array_column($page->attributes('link[rel="stylesheet"], link[rel="preload"]'), 'href'),
        ...array_column($page->attributes('.site-head img'), 'src'),
        ...array_column($page->attributes('.site-head source'), 'srcset'),
    ];

    expect($addresses)->toHaveCount(6);
    foreach ($addresses as $address) {
        expect($address)->toMatch('#^/assets/[0-9a-f]{16}/(css|fonts|images)/[A-Za-z0-9.-]+$#');
    }
});

it('serves styles for a year', function (string $file) {
    $address = stylesheet($this, $file);

    $this->get($address)
        ->assertOk()
        ->assertHeader('Content-Type', 'text/css; charset=UTF-8')
        ->assertHeader('Cache-Control', A_YEAR);
})->with(['css/tokens.css', 'css/site.css']);

it('serves the fonts the styles use for a year, at fingerprinted addresses', function () {
    $css = $this->get(stylesheet($this, 'css/tokens.css'))->assertOk()->getContent();
    preg_match_all('/url\("?([^")]+)"?\)/', (string) $css, $urls);

    expect($urls[1])->toHaveCount(5);
    foreach ($urls[1] as $font) {
        expect($font)->toMatch('#^/assets/[0-9a-f]{16}/fonts/[A-Za-z-]+\.woff2$#');
        $response = $this->get($font)->assertOk()->assertHeader('Content-Type', 'font/woff2')->assertHeader('Cache-Control', A_YEAR);
        expect(md5((string) $response->getContent()))->toBe(md5_file(public_path(preg_replace('#^/assets/[0-9a-f]{16}/#', '', $font))));
    }
});

it('preloads the same font addresses the styles use, so no font is fetched twice', function () {
    $css = (string) $this->get(stylesheet($this, 'css/tokens.css'))->getContent();
    $preloads = array_column(ReaderPage::of($this->get('/articles/receive-at-the-dock'))->attributes('link[rel="preload"]'), 'href');

    expect($preloads)->not->toBe([]);
    foreach ($preloads as $font) {
        expect($css)->toContain("url(\"{$font}\")");
    }
});

it('answers page not found for an address whose fingerprint is not the file\'s', function (string $address) {
    $this->get($address)->assertNotFound();
})->with([
    'a style' => '/assets/0123456789abcdef/css/site.css',
    'a font' => '/assets/0123456789abcdef/fonts/BarlowCondensed-Bold.woff2',
]);

it('serves nothing from outside public/ or that isn\'t a style, font or image', function (string $path) {
    $this->get('/assets/0123456789abcdef/'.$path)->assertNotFound();
})->with([
    'the front controller' => 'index.php',
    'a way out' => '..%2F.env',
    'a missing file' => 'css/nothing.css',
]);

it('lets no page be cached for a year', function () {
    $this->get('/articles/receive-at-the-dock')->assertOk();

    expect($this->get('/articles/receive-at-the-dock')->headers->get('Cache-Control'))->not->toContain('max-age=31536000');
});

/** The address the Article page links a stylesheet at. */
function stylesheet(TestCase $test, string $file): string
{
    $hrefs = array_column(ReaderPage::of($test->get('/articles/receive-at-the-dock'))->attributes('link[rel="stylesheet"]'), 'href');
    $address = collect($hrefs)->first(fn (string $href): bool => str_ends_with($href, '/'.$file));
    expect($address)->toBeString();

    return (string) $address;
}

describe('fingerprints worked out once, at deploy', function () {
    beforeEach(function () {
        $this->style = 'css/zz-test-'.Str::lower(Str::random(8)).'.css';
        File::put(public_path($this->style), "body { color: black; }\n");
    });

    afterEach(function () {
        File::delete(public_path($this->style));
        $this->artisan('help:clear-assets');
    });

    it('serves files at the fingerprints help:cache-assets worked out, until they are cleared', function () {
        $page = fn (): array => ReaderPage::of($this->get('/articles/receive-at-the-dock'))->attributes('link[rel="stylesheet"]');
        $before = $page();
        $this->artisan('help:cache-assets')->expectsOutputToContain('Worked out the fingerprints of')->assertSuccessful();
        $cached = Asset::url($this->style);

        // A file changed after the deploy keeps the address worked out at the deploy (deploys
        // replace every file at once, so this only happens by hand), until the cache is cleared.
        File::put(public_path($this->style), "body { color: red; }\n");
        expect($page())->toBe($before);
        $this->get($cached)->assertOk()->assertSee('color: red');

        $this->artisan('help:clear-assets')->assertSuccessful();
        $this->get($cached)->assertNotFound();
        expect(Asset::url($this->style))->not->toBe($cached)
            ->and($page())->toBe($before);
    });
});
