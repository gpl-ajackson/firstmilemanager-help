<?php

use Tests\Support\BrowserLayout;
use Tests\Support\ReaderPage;
use Tests\Support\ScriptlessBrowser;

/*
 * Spec #30, story 60: with no script running, the search form still works and shows results,
 * and the results page fits a 320px handheld.
 */

beforeEach(function () {
    content()
        ->article('build-a-pallet', ['title' => 'Build a pallet'])
        ->article('count-stock', ['title' => 'Count stock'])
        ->publish();
});

it('returns the results page when the form is submitted with script off', function (string $from) {
    $request = ScriptlessBrowser::submitSearch($this->get($from), 'palet');

    expect($request)->toBe('/search?q=palet')
        ->and(ReaderPage::of($this->get($request))->texts('.results .result-title'))->toBe(['Build a pallet']);
})->with([
    'the search page' => ['/search'],
    'a results page' => ['/search?q=count'],
]);

it('fits a handheld without sideways scrolling', function (int $width, string $address) {
    content()
        ->article('a-long-title', [
            'title' => 'Receive a mixed-SKU delivery onto Dock Staging locations',
            'audience_tier' => 'Client',
        ], "## Count the cartons on the pallet {#count-cartons-on-the-pallet}\n\nCount them.\n")
        ->glossaryEntry('advanced-shipping-notice-asn', ['term' => 'Advanced Shipping Notice (ASN)', 'other_names' => ['pre-advice']])
        ->publish();

    $layout = BrowserLayout::of($this->get($address), width: $width, selectors: ['.search-page']);

    expect($layout->scrollWidth)->toBeLessThanOrEqual($width);
})->with([320, 390, 1280])->with([
    'results' => ['/search?q=cartons+delivery'],
    'no results' => ['/search?q=forklift'],
    'a Glossary answer' => ['/search?q=pre-advice'],
]);
