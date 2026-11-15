<?php

use Tests\Support\ReaderPage;

/*
| Pasted messages (spec #30, user story 50; "Search"): an Operative pastes a whole Stockist
| message, with their own codes in it, and the Troubleshooting Article that quotes it comes
| first. Each message is indexed as a pattern whose Placeholders take any words.
*/

it('puts the Troubleshooting Article first for a pasted message with the reader\'s own codes', function (string $pasted) {
    content()
        // An Article holding every pasted word, codes included, so ranking by words alone would put it first.
        ->article('move-a-container', ['title' => 'Move a container to a location', 'summary' => 'How to take a container to another location.'],
            "Location B2 is too small, so it can't take container 0042. Try location A1A1 for LPN containers.\n")
        ->article('container-sizes', ['title' => 'Container sizes', 'type' => 'Reference', 'summary' => 'Which container a location can take.'])
        ->article('location-too-small', [
            'title' => 'The location is too small',
            'type' => 'Troubleshooting',
            'summary' => 'What to do when a location has no room for a container.',
            'messages' => ["Location [Location code] can't take container [Container code]: it is too small."],
        ])
        ->publish();

    $page = ReaderPage::of($this->get('/search?'.http_build_query(['q' => $pasted])));

    expect($page->texts('.results .result-title')[0])->toBe('The location is too small')
        ->and(whereResultsOpen($this, $page)[0][1])->toBe('/articles/location-too-small');
})->with([
    'as Stockist shows it' => ["Location A1A1 can't take container LPN-0042: it is too small."],
    'codes with spaces' => ["Location COL-DS-OUT 01 can't take container Pallet 7: it is too small."],
    'retyped: capitals, a curly apostrophe, no full stop' => ['location a1a1 can’t take container lpn-0042: it is too small'],
    'with words around it' => ["Error: Location A1A1 can't take container LPN-0042: it is too small. (scanner 3)"],
]);

it('doesn\'t treat a message with a different fixed word as the pasted one', function () {
    content()
        ->article('move-a-container', ['title' => 'Move a container to a location'],
            "Location A1A1 is too large, so it can take container 0042.\n")
        ->article('location-too-small', [
            'title' => 'The location is too small',
            'type' => 'Troubleshooting',
            'messages' => ["Location [Location code] can't take container [Container code]: it is too small."],
        ])
        ->publish();

    $page = ReaderPage::of($this->get('/search?'.http_build_query(['q' => 'Location A1A1 can take container 0042: it is too large.'])));

    expect($page->texts('.results .result-title')[0])->toBe('Move a container to a location');
});

it('puts every Troubleshooting Article quoting the pasted message first, staff ones before Client tier', function () {
    content()
        ->article('stock-in-quarantine', ['title' => 'Stock in quarantine', 'summary' => 'Why stock is held back.'],
            "Location Q1 is quarantined. Location Q1 holds stock that is quarantined.\n")
        ->article('client-stock-held', [
            'title' => 'Your stock is held',
            'type' => 'Troubleshooting',
            'audience_tier' => 'Client',
            'messages' => ['Location [Location code] is quarantined.'],
        ])
        ->article('location-quarantined', [
            'title' => 'A location is quarantined',
            'type' => 'Troubleshooting',
            'messages' => ['Stock cannot be picked.', 'Location [Location code] is quarantined.'],
        ])
        ->publish();

    $page = ReaderPage::of($this->get('/search?'.http_build_query(['q' => 'Location Q1 is quarantined'])));

    expect(array_slice($page->texts('.results .result-title'), 0, 2))->toBe(['A location is quarantined', 'Your stock is held']);
});

it('never lets a message made only of Placeholders match everything', function () {
    content()
        ->article('count-stock', ['title' => 'Count stock'], "Count the stock in each location.\n")
        ->article('anything', ['title' => 'Any message', 'type' => 'Troubleshooting', 'messages' => ['[Message]']])
        ->publish();

    $page = ReaderPage::of($this->get('/search?q=count+stock'));

    expect($page->texts('.results .result-title'))->toBe(['Count stock']);
});

it('puts the Troubleshooting Article first even when the words alone rank it below the suggestions shown', function () {
    $content = content();
    foreach (['one', 'two', 'three', 'four', 'five', 'six'] as $n) {
        $content->article("container-sizes-{$n}", ['title' => "Location and container sizes {$n}", 'type' => 'Reference', 'summary' => 'Which container a location can take.']);
    }
    $content
        ->article('make-room', [
            'title' => 'Make room on a shelf',
            'type' => 'Troubleshooting',
            'summary' => 'What to do when a shelf is full.',
            'messages' => ["Location [Location code] can't take container [Container code]: it is too small."],
        ])
        ->publish();

    $suggestions = $this->getJson('/suggestions?'.http_build_query(['q' => "Location A1A1 can't take container LPN-0042: it is too small."]))->json('suggestions');

    expect($suggestions[0])->toMatchArray(['title' => 'Make room on a shelf', 'address' => '/articles/make-room', 'section' => null])
        ->and($suggestions)->toHaveCount(5);
});
