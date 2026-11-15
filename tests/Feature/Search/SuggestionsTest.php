<?php

/*
 * Suggestions as the reader types (spec #30, "Search"; user story 49): the search box's script
 * asks the search service's suggestions address for each change, and shows what comes back.
 * Suggestions are never recorded; a Search is recorded only once it settles.
 */

use App\Records\Records;
use Carbon\CarbonImmutable;
use Tests\Support\ReaderPage;
use Tests\TestCase;

/**
 * The titles the suggestions address gives for $typed, best first.
 *
 * @return list<string>
 */
function suggestedTitles(TestCase $test, string $typed): array
{
    $suggestions = $test->withHeader('User-Agent', A_READER)->getJson('/suggestions?q='.rawurlencode($typed))
        ->assertOk()
        ->json('suggestions');

    return array_column($suggestions, 'title');
}

it('suggests Articles for a last word still being typed', function () {
    content()
        ->article('quarantine-stock', ['title' => 'Quarantine stock'])
        ->article('count-stock', ['title' => 'Count stock'])
        ->publish();

    expect(suggestedTitles($this, 'quaran'))->toBe(['Quarantine stock']);
});

it('matches a last word shorter than three letters, or one followed by a space, only as a whole word', function (string $typed, array $suggested) {
    content()
        ->article('raise-a-po', ['title' => 'Raise a PO'])
        ->article('positions', ['title' => 'Positions in a location'])
        ->article('pallets', ['title' => 'Build a pallet'])
        ->publish();

    expect(suggestedTitles($this, $typed))->toBe($suggested);
})->with([
    'PO' => ['PO', ['Raise a PO']],
    'pal' => ['pal', ['Build a pallet']],
    'pal and a space' => ['pal ', []],
    'raise a po' => ['raise a po', ['Raise a PO']],
]);

it('suggests each Article with where it opens, its type, area and whether it is for clients', function () {
    content()
        ->article('receive-a-delivery', ['title' => 'Receive a delivery', 'type' => 'How-to', 'product_area' => 'Inbound'],
            "## Record damaged deliveries {#damage}\n\nSay what is damaged.\n")
        ->article('book-a-delivery', ['title' => 'Book a delivery', 'type' => 'How-to', 'product_area' => 'Inbound', 'audience_tier' => 'Client'])
        ->article('delivery-1', ['title' => 'Delivery one'])
        ->article('delivery-2', ['title' => 'Delivery two'])
        ->article('delivery-3', ['title' => 'Delivery three'])
        ->publish();

    $suggestions = $this->getJson('/suggestions?q=deliv')->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->json('suggestions');

    expect($suggestions)->toHaveCount(5)
        ->and(collect($suggestions)->firstWhere('title', 'Receive a delivery'))->toMatchArray([
            'address' => '/articles/receive-a-delivery',
            'opens_at' => '/articles/receive-a-delivery',
            'section' => null,
            'type' => 'How-to',
            'product_area' => 'Inbound',
            'for_clients' => false,
        ])
        ->and(end($suggestions))->toMatchArray(['place' => 5, 'title' => 'Book a delivery', 'for_clients' => true]);
});

it('suggests only the best five', function () {
    $content = content();
    foreach (range(1, 7) as $n) {
        $content->article("pallet-{$n}", ['title' => "Pallet {$n}"]);
    }
    $content->publish();

    expect(suggestedTitles($this, 'pall'))->toHaveCount(5);
});

it('suggests opening an Article at the Section whose heading matched', function () {
    content()
        ->article('receive-a-delivery', ['title' => 'Receive a delivery'],
            "## Record damaged stock {#damage}\n\nSay what is damaged.\n")
        ->publish();

    expect($this->getJson('/suggestions?q=damag')->json('suggestions.0'))->toMatchArray([
        'address' => '/articles/receive-a-delivery#damage',
        'opens_at' => '/articles/receive-a-delivery#damage',
        'section' => 'Record damaged stock',
    ]);
});

it('never records a suggestion or counts it as a page view, and sets no cookie', function () {
    config(['help.going_public' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00', 'Europe/London'));
    content()->article('quarantine-stock', ['title' => 'Quarantine stock'])->publish();

    foreach (['q', 'qu', 'qua', 'quar', 'quarantine'] as $typed) {
        $response = $this->withHeader('User-Agent', A_READER)->getJson('/suggestions?q='.$typed)->assertOk();
        expect($response->headers->getCookies())->toBeEmpty();
    }

    $day = CarbonImmutable::parse('2026-11-03');
    expect(app(Records::class)->searches($day, $day))->toBe([])
        ->and(app(Records::class)->pageViews($day, $day))->toBe([]);
});

it('suggests nothing for blank words', function () {
    content()->article('quarantine-stock', ['title' => 'Quarantine stock'])->publish();

    $this->getJson('/suggestions?q=+')->assertOk()->assertExactJson(['words' => '', 'glossary_answers' => [], 'suggestions' => []]);
    $this->getJson('/suggestions')->assertOk()->assertExactJson(['words' => '', 'glossary_answers' => [], 'suggestions' => []]);
});

it('suggests nothing, saying search isn\'t working, when Meilisearch can\'t be reached', function () {
    content()->article('quarantine-stock', ['title' => 'Quarantine stock'])->publish();
    config(['help.search.host' => 'http://127.0.0.1:9']);

    $this->getJson('/suggestions?q=quaran')->assertStatus(503)
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertExactJson(['words' => 'quaran', 'glossary_answers' => [], 'suggestions' => []]);
});

/*
 * Settling from the script: when the reader stops typing for two seconds with three letters or
 * more, or opens a suggestion, the script settles the words at POST /searches. The Search holds
 * what the reader was shown: the suggestions for those words.
 */

/** @return list<array<string, mixed>> */
function searchesRecordedOn(string $day): array
{
    $date = CarbonImmutable::parse($day);

    return array_map(fn ($record) => $record->toArray(), app(Records::class)->searches($date, $date));
}

it('settles the words as the suggestions showed them', function () {
    config(['help.going_public' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00', 'Europe/London'));
    content()
        ->article('quarantine-stock', ['title' => 'Quarantine stock'])
        ->article('count-stock', ['title' => 'Count stock'])
        ->publish();

    $settled = $this->withHeaders(postedFromAHelpPage())->postJson('/searches', ['q' => 'quaran'])->assertCreated();
    $search = $settled->json('search');

    expect($settled->json('results'))->toHaveCount(1)
        ->and($settled->json('results.0'))->toMatchArray([
            'place' => 1,
            'title' => 'Quarantine stock',
            'address' => "/searches/{$search}/opened/1/articles/quarantine-stock",
            'opens_at' => '/articles/quarantine-stock',
        ])
        ->and(searchesRecordedOn('2026-11-03'))->toBe([[
            'words' => 'quaran',
            'results_shown' => 1,
            'glossary_entry_shown' => false,
            'outcome' => 'Not opened',
            'opened_result' => null,
            'opened_place' => null,
            'day' => '2026-11-03',
            'where' => 'help centre',
        ]]);

    $this->withHeader('User-Agent', A_READER)->get($settled->json('results.0.address'))
        ->assertRedirect('/articles/quarantine-stock');
    expect(searchesRecordedOn('2026-11-03')[0])->toMatchArray(['outcome' => 'Opened', 'opened_place' => 1]);
});

it('keeps one Search when the reader then asks for every result, and counts any of them opened', function () {
    config(['help.going_public' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00', 'Europe/London'));
    $content = content();
    foreach (range(1, 7) as $n) {
        $content->article("pallet-{$n}", ['title' => "Pallet {$n}"]);
    }
    $content->publish();

    $search = $this->withHeaders(postedFromAHelpPage())->postJson('/searches', ['q' => 'pallet'])->json('search');
    expect(searchesRecordedOn('2026-11-03')[0]['results_shown'])->toBe(5);

    // The reader presses Enter: the results page shows all seven under the same Search.
    $page = ReaderPage::of($this->withHeader('User-Agent', A_READER)->get("/search?q=pallet&search={$search}"));
    $seventh = $page->links('.results .result-title a')[6][1];
    $this->withHeader('User-Agent', A_READER)->get($seventh)->assertRedirect();

    expect(searchesRecordedOn('2026-11-03'))->toHaveCount(1)
        ->and(searchesRecordedOn('2026-11-03')[0])->toMatchArray(['results_shown' => 7, 'outcome' => 'Opened', 'opened_place' => 7]);
});

it('refuses a settle sent from another site, recording nothing', function (array $headers) {
    config(['help.going_public' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00', 'Europe/London'));
    content()->article('quarantine-stock', ['title' => 'Quarantine stock'])->publish();

    $this->withHeaders(['User-Agent' => A_READER, ...$headers])->postJson('/searches', ['q' => 'quarantine'])
        ->assertForbidden()
        ->assertHeader('X-Robots-Tag', 'noindex');

    expect(searchesRecordedOn('2026-11-03'))->toBe([]);
})->with([
    'another site, by Sec-Fetch-Site' => [['Origin' => 'https://example.com', 'Sec-Fetch-Site' => 'cross-site']],
    'another site, by Origin alone' => [['Origin' => 'https://example.com']],
    'neither' => [[]],
]);

it('suggests a Glossary Entry for its term or an Other Name, above the Articles', function () {
    content()
        ->article('move-stock', ['title' => 'Move stock to another location'])
        ->glossaryEntry('location', ['other_names' => ['bin']], "A Location is one place in the warehouse where stock is kept.\n")
        ->publish();

    $suggested = $this->getJson('/suggestions?q=bin')->assertOk();

    expect($suggested->json('glossary_answers'))->toBe([[
        'place' => 1,
        'term' => 'Location',
        'also_called' => 'bin',
        'address' => '/glossary/location',
        'opens_at' => '/glossary/location',
        'for_clients' => false,
    ]])
        ->and(suggestedTitles($this, 'location'))->toBe(['Move stock to another location'])
        ->and($this->getJson('/suggestions?q=location')->json('suggestions.0.place'))->toBe(2);
});
