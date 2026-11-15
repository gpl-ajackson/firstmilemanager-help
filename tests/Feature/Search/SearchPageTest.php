<?php

use Tests\Support\ReaderPage;

it('finds a published Article by its title and links to it', function () {
    content()
        ->article('receive-a-delivery', ['title' => 'Receive a delivery at the dock'])
        ->article('count-stock', ['title' => 'Count stock in a location'])
        ->publish();

    $page = ReaderPage::of($this->get('/search?q=delivery'));

    expect(whereResultsOpen($this, $page))
        ->toBe([['Receive a delivery at the dock', '/articles/receive-a-delivery']]);
});

it('ranks a title match first, then summary and Section headings, then body', function () {
    content()
        ->article('in-the-body', ['title' => 'Move stock', 'summary' => 'How to move stock between locations.'],
            "Stock waiting in quarantine can't be moved.\n")
        ->article('in-a-heading', ['title' => 'Check stock', 'summary' => 'How to check stock in a location.'],
            "## Release it from quarantine\n\nChoose Release.\n")
        ->article('in-the-title', ['title' => 'Quarantine stock', 'summary' => 'How to hold stock back from picking.'])
        ->article('in-the-summary', ['title' => 'Hold stock', 'summary' => 'How to put stock in quarantine.'])
        ->publish();

    $page = ReaderPage::of($this->get('/search?q=quarantine'));

    $titles = $page->texts('.results .result-title');

    // Summary and Section headings rank as one: either order between them is right.
    expect($titles)->toHaveCount(4)
        ->and($titles[0])->toBe('Quarantine stock')
        ->and([$titles[1], $titles[2]])->toEqualCanonicalizing(['Hold stock', 'Check stock'])
        ->and($titles[3])->toBe('Move stock');
});

it('opens the Article at the Section whose heading matched', function () {
    content()
        ->article('receive-a-delivery', ['title' => 'Receive a delivery'],
            "## In short\n\nScan each carton.\n\n## Count the cartons {#count-cartons}\n\nCount them.\n\n## Record damaged stock {#damage}\n\nSay what is damaged.\n")
        ->publish();

    $page = ReaderPage::of($this->get('/search?q=damaged'));

    expect(whereResultsOpen($this, $page))->toBe([['Receive a delivery', '/articles/receive-a-delivery#damage']])
        ->and($page->text('.results .result-section'))->toBe('Opens at Record damaged stock');
});

it('opens the Article at the top when its title matches as well as a heading does', function () {
    content()
        ->article('receive-a-delivery', ['title' => 'Receive a delivery'],
            "## Receive a delivery with damage {#damage}\n\nSay what is damaged.\n")
        ->publish();

    $page = ReaderPage::of($this->get('/search?q=receive+delivery'));

    expect(whereResultsOpen($this, $page))->toBe([['Receive a delivery', '/articles/receive-a-delivery']])
        ->and($page->has('.results .result-section'))->toBeFalse();
});

it('opens at the top when the matched heading has no Section Name', function () {
    content()
        ->article('receive-a-delivery', ['title' => 'Receive a delivery'], "## Record damaged stock\n\nSay what is damaged.\n")
        ->publish();

    $page = ReaderPage::of($this->get('/search?q=damaged'));

    expect(whereResultsOpen($this, $page))->toBe([['Receive a delivery', '/articles/receive-a-delivery']]);
});

it('tolerates a typo in a word of five letters or more', function (string $typed, string $finds) {
    content()
        ->article('quarantine-stock', ['title' => 'Quarantine stock'])
        ->article('build-a-pallet', ['title' => 'Build a pallet'])
        ->publish();

    expect(ReaderPage::of($this->get('/search?q='.$typed))->texts('.results .result-title'))->toBe([$finds]);
})->with([
    'quarentine' => ['quarentine', 'Quarantine stock'],
    'palet' => ['palet', 'Build a pallet'],
]);

it('matches words shorter than five letters exactly', function (string $typed) {
    content()
        ->article('raise-a-po', ['title' => 'Raise a PO'])
        ->article('send-an-asn', ['title' => 'Send an ASN'])
        ->article('choose-a-dock', ['title' => 'Choose a dock door'])
        ->article('pod', ['title' => 'Proof of delivery', 'summary' => 'What a POD is and where to find it.'])
        ->article('positions', ['title' => 'Positions in a location'])
        ->article('send-ans', ['title' => 'ANS codes'])
        ->publish();

    expect(ReaderPage::of($this->get('/search?q='.$typed))->texts('.results .result-title'))->toBe(match ($typed) {
        'PO' => ['Raise a PO'],
        'ASN' => ['Send an ASN'],
        'dokc', 'dck' => [],
    });
})->with(['PO', 'ASN', 'dokc', 'dck']);

it('shows each result\'s Article Type, Product Area and "For your clients" when Client tier', function () {
    content()
        ->article('book-a-delivery', ['title' => 'Book a delivery', 'type' => 'How-to', 'product_area' => 'Inbound', 'audience_tier' => 'Client'])
        ->article('delivery-statuses', ['title' => 'Delivery statuses', 'type' => 'Reference', 'product_area' => 'Inventory'])
        ->publish();

    $page = ReaderPage::of($this->get('/search?q=delivery'));

    expect($page->texts('.results .result-meta'))->toBe([
        'Reference Inventory',
        'How-to Inbound For your clients',
    ]);
});

it('ranks Client-tier results below staff ones on Stockist\'s centre', function () {
    content()
        ->article('client-quarantine', ['title' => 'Quarantine', 'summary' => 'What quarantine means for your stock.', 'audience_tier' => 'Client'])
        ->article('count-stock', ['title' => 'Count stock', 'summary' => 'How to count stock.'], "Stock in quarantine is counted too.\n")
        ->publish();

    expect(ReaderPage::of($this->get('/search?q=quarantine'))->texts('.results .result-title'))->toBe(['Count stock', 'Quarantine']);
});

it('never finds a Draft, a Retired Article or a Release Note', function () {
    content()
        ->article('cross-dock-a-delivery', ['title' => 'Cross-dock a delivery'])
        ->article('cross-dock-draft', ['title' => 'Cross-dock a pallet', 'status' => 'Draft'])
        ->article('cross-dock-retired', ['title' => 'Cross-dock by hand', 'status' => 'Retired', 'what_changed' => 'Cross-docking is now done at the dock terminal.'])
        ->article('cross-dock-replaced', ['title' => 'Cross-dock old way', 'status' => 'Retired', 'replacement' => 'cross-dock-a-delivery'])
        ->releaseNote('2026-10-24', ['summary' => 'Cross-dock at the terminal.', 'changes' => [['text' => 'You can now cross-dock at the dock terminal.']]])
        ->publish();

    expect(ReaderPage::of($this->get('/search?q=cross-dock'))->texts('.results .result-title'))->toBe(['Cross-dock a delivery']);
});

it('drops an Article from search once it is no longer Published', function () {
    content()->article('cross-dock-a-delivery', ['title' => 'Cross-dock a delivery'])->publish();
    content()->article('cross-dock-a-delivery', ['title' => 'Cross-dock a delivery', 'status' => 'Draft'])->publish();

    $page = ReaderPage::of($this->get('/search?q=cross-dock'));

    expect($page->has('.results'))->toBeFalse()
        ->and($page->text('.search-none h2'))->toBe('No results for “cross-dock”');
});

it('offers Troubleshooting, the Glossary and the Product Areas when nothing is found', function () {
    content()
        ->article('receive-a-delivery', ['product_area' => 'Inbound'])
        ->article('count-stock', ['product_area' => 'Inventory'])
        ->article('a-draft', ['product_area' => 'Warehouses', 'status' => 'Draft'])
        ->publish();

    $page = ReaderPage::of($this->get('/search?q=forklift'));

    expect($page->text('.search-none h2'))->toBe('No results for “forklift”')
        ->and($page->links('.search-none a'))->toBe([
            ['Troubleshooting Fixes for messages and problems', '/troubleshooting'],
            ['Glossary What First Mile Manager\'s words mean', '/glossary'],
            ['Inbound', '/areas/inbound'],
            ['Inventory', '/areas/inventory'],
        ]);
});

it('asks for words when the search box was left empty', function () {
    content()->article('receive-a-delivery')->publish();

    $page = ReaderPage::of($this->get('/search?q=+'));

    expect($page->has('.results'))->toBeFalse()
        ->and($page->has('.search-none'))->toBeFalse()
        ->and($page->text('.search-hint'))->toBe('Type a question or a few words, or paste a message from First Mile Manager.');
});

it('keeps the words searched for in the search box', function () {
    content()->article('receive-a-delivery')->publish();

    $this->get('/search?q='.urlencode('receive "a" delivery'))
        ->assertOk()
        ->assertSee('value="receive &quot;a&quot; delivery"', escape: false);
});

it('is never indexed, even once going public is on', function () {
    content()->article('receive-a-delivery')->publish();
    config(['help.going_public' => true]);

    $this->get('/search?q=delivery')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertSee('<meta name="robots" content="noindex">', escape: false);
});

it('sets no cookie', function () {
    content()->article('receive-a-delivery')->publish();

    $response = $this->get('/search?q=delivery')->assertOk();

    expect($response->headers->getCookies())->toBeEmpty();
});
