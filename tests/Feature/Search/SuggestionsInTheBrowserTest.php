<?php

use App\Records\Records;
use Carbon\CarbonImmutable;
use Tests\Support\SearchingReader;

/*
 * The search box's script in a real browser (spec #30, "Search"; user story 49): suggestions as
 * the reader types, never recorded; a Search recorded once it settles, by Enter or Search, by
 * opening a result, or by two seconds idle with three letters or more. The page's clock is held,
 * so "two seconds idle" is a step. Nothing is kept on the reader's device.
 */

/** @return list<array<string, mixed>> */
function searchesSettled(): array
{
    $day = CarbonImmutable::parse('2026-11-03');

    return array_map(fn ($record) => $record->toArray(), app(Records::class)->searches($day, $day));
}

beforeEach(function () {
    config(['help.going_public' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00', 'Europe/London'));

    content()
        ->article('quarantine-stock', ['title' => 'Quarantine stock'])
        ->article('build-a-pallet', ['title' => 'Build a pallet'])
        ->article('label-a-pallet', ['title' => 'Label a pallet'])
        ->publish();
});

it('shows suggestions as the reader types, and records nothing', function () {
    $reader = SearchingReader::visit($this, '/search', [['type', 'quaran'], ['idle', 300], ['see', 'Quarantine stock']]);

    expect($reader->page()->texts('.suggestions a .suggestion-title'))->toBe(['Quarantine stock'])
        ->and($reader->requestsTo('GET /suggestions'))->not->toBeEmpty()
        ->and($reader->requestsTo('POST'))->toBe([])
        ->and(searchesSettled())->toBe([]);
});

it('settles and records one Search when the reader stops for two seconds with three letters typed', function () {
    $reader = SearchingReader::visit($this, '/search', [['type', 'pallet'], ['idle', 2000]]);

    expect(searchesSettled())->toHaveCount(1)
        ->and(searchesSettled()[0])->toMatchArray(['words' => 'pallet', 'results_shown' => 2, 'outcome' => 'Not opened'])
        ->and($reader->requestsTo('POST'))->toBe(['POST /searches'])
        // The suggestions shown now open through the settled Search.
        ->and($reader->page()->links('.suggestions a'))->toHaveCount(2)
        ->each(fn ($link) => $link->{1}->toMatch('#^/searches/[0-9a-f-]{36}/opened/[12]/articles/(build|label)-a-pallet$#'));
});

it('records the same words once, however long the reader then waits', function () {
    $reader = SearchingReader::visit($this, '/search', [['type', 'pallet'], ['idle', 2000], ['idle', 5000], ['type', ' '], ['idle', 5000]]);

    expect($reader->requestsTo('POST'))->toBe(['POST /searches'])
        ->and(searchesSettled())->toHaveCount(1);
});

it('records nothing for two letters, or for a reader who stops for less than two seconds', function (array $steps) {
    $reader = SearchingReader::visit($this, '/search', $steps);

    expect($reader->requestsTo('POST'))->toBe([])
        ->and(searchesSettled())->toBe([]);
})->with([
    'two letters, then idle' => [[['type', 'pa'], ['idle', 5000]]],
    'three letters, then not quite two seconds' => [[['type', 'pal'], ['idle', 1999]]],
    'typing on before two seconds' => [[['type', 'pal'], ['idle', 1500], ['type', 'l'], ['idle', 1500]]],
]);

it('records one Search when the reader presses Enter or Search', function (array $send) {
    $reader = SearchingReader::visit($this, '/search', [['type', 'pallet'], $send]);

    expect($reader->url)->toBe('/search?q=pallet')
        ->and(searchesSettled())->toHaveCount(1)
        ->and(searchesSettled()[0])->toMatchArray(['words' => 'pallet', 'results_shown' => 2]);
})->with([
    'Enter' => [['press', 'Enter']],
    'Search' => [['click', 'Search']],
]);

it('records one Search when the reader presses Enter after the script settled the words', function () {
    $reader = SearchingReader::visit($this, '/search', [['type', 'pallet'], ['idle', 2000], ['press', 'Enter'], ['open', 'Label a pallet']]);

    $search = searchesSettled()[0];
    expect($reader->url)->toBe('/articles/label-a-pallet')
        ->and($reader->requestsTo('GET /search?'))->toHaveCount(1)
        ->and($reader->requestsTo('GET /search?')[0])->toMatch('#^GET /search\?q=pallet&search=[0-9a-f-]{36}$#')
        ->and(searchesSettled())->toHaveCount(1)
        ->and($search)->toMatchArray(['words' => 'pallet', 'outcome' => 'Opened', 'opened_result' => '/articles/label-a-pallet']);
});

it('records one Search, Opened, when the reader opens a suggestion', function (array $before) {
    $reader = SearchingReader::visit($this, '/search', [['type', 'pallet'], ...$before, ['open', 'Label a pallet']]);

    expect($reader->url)->toBe('/articles/label-a-pallet')
        ->and(searchesSettled())->toHaveCount(1)
        ->and(searchesSettled()[0])->toMatchArray([
            'words' => 'pallet',
            'results_shown' => 2,
            'outcome' => 'Opened',
            'opened_result' => '/articles/label-a-pallet',
            'opened_place' => 2,
        ]);
})->with([
    'straight away' => [[['idle', 300]]],
    'after two seconds idle' => [[['idle', 2000]]],
]);

it('writes nothing to the reader\'s device', function () {
    $reader = SearchingReader::visit($this, '/search', [['type', 'pallet'], ['idle', 2000], ['open', 'Build a pallet']]);

    expect($reader->url)->toBe('/articles/build-a-pallet')
        ->and($reader->cookies)->toBe(0)
        ->and($reader->storage)->toBe(['local' => 0, 'session' => 0, 'indexedDb' => 0]);
});

it('lists a Glossary answer first, and opening it records the Search Opened at place 1', function () {
    content()
        ->article('move-stock', ['title' => 'Move stock between locations'])
        ->glossaryEntry('location', ['other_names' => ['bin']], "A Location is one place in the warehouse where stock is kept.\n")
        ->publish();

    $reader = SearchingReader::visit($this, '/search', [['type', 'bin'], ['idle', 300], ['see', 'Location'], ['open', 'Location']]);

    expect($reader->url)->toBe('/glossary/location')
        ->and(searchesSettled())->toHaveCount(1)
        ->and(searchesSettled()[0])->toMatchArray([
            'words' => 'bin',
            'glossary_entry_shown' => true,
            'outcome' => 'Opened',
            'opened_result' => '/glossary/location',
            'opened_place' => 1,
        ]);
});

it('fits suggestions on a 320 pixel screen with no sideways scrolling', function () {
    content()
        ->article('book-a-delivery', [
            'title' => 'Book a delivery slot for an incoming container at the dock',
            'audience_tier' => 'Client',
            'product_area' => 'Inbound',
        ], "## Choose a delivery window when the dock is busy {#busy-dock}\n\nPick one.\n")
        ->publish();

    $reader = SearchingReader::visit($this, '/search', [['type', 'delivery window'], ['idle', 300], ['see', 'For your clients']], width: 320);

    expect($reader->page()->texts('.suggestions a .suggestion-title'))->toBe(['Book a delivery slot for an incoming container at the dock'])
        ->and($reader->scrollWidth)->toBeLessThanOrEqual(320);
});
