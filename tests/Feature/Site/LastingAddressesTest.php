<?php

/*
| Addresses that last (ADR-0001, #40): a link an Account Manager emailed a client keeps working
| whatever happens to the Article.
*/

it('redirects a former address for good to the Article\'s current address', function () {
    content()
        ->article('receive-at-the-dock', ['former_addresses' => ['/articles/receive-a-shipment']])
        ->publish();

    $this->get('/articles/receive-a-shipment')
        ->assertStatus(301)
        ->assertRedirect('/articles/receive-at-the-dock');
});

it('keeps the query string when it redirects an old address', function () {
    content()
        ->article('receive-at-the-dock', ['former_addresses' => ['/articles/receive-a-shipment']])
        ->publish();

    $this->get('/articles/receive-a-shipment?utm_source=email')
        ->assertStatus(301)
        ->assertRedirect('/articles/receive-at-the-dock?utm_source=email');
});

it('redirects a Retired Article for good to its replacement', function () {
    content()
        ->article('book-a-receiving-slot', ['status' => 'Retired', 'replacement' => 'book-in-a-delivery'])
        ->article('book-in-a-delivery')
        ->publish();

    $this->get('/articles/book-a-receiving-slot')
        ->assertStatus(301)
        ->assertRedirect('/articles/book-in-a-delivery');
    $this->get('/articles/book-in-a-delivery')->assertOk();
});

it('sends a Retired Article\'s former address straight to the replacement', function () {
    content()
        ->article('book-a-receiving-slot', [
            'status' => 'Retired',
            'replacement' => 'book-in-a-delivery',
            'former_addresses' => ['/articles/book-a-slot'],
        ])
        ->article('book-in-a-delivery')
        ->publish();

    $this->get('/articles/book-a-slot')->assertStatus(301)->assertRedirect('/articles/book-in-a-delivery');
});

it('shows a Retired Article with no replacement as a short notice of what changed, with its Product Area and search', function () {
    content()
        ->article('print-a-dock-sheet', [
            'title' => 'Print a dock sheet',
            'product_area' => 'Inbound',
            'status' => 'Retired',
            'what_changed' => 'First Mile Manager no longer prints dock sheets: the Dock Receiving Terminal shows each shipment instead.',
        ], "## Steps\n\nPress Print on the shipment.\n")
        ->article('receive-at-the-dock', ['product_area' => 'Inbound'])
        ->publish();

    $this->get('/articles/print-a-dock-sheet')
        ->assertStatus(410)
        ->assertSee('<title>Print a dock sheet (retired) | First Mile Manager Help centre</title>', escape: false)
        ->assertSee('Print a dock sheet')
        ->assertSee('First Mile Manager no longer prints dock sheets: the Dock Receiving Terminal shows each shipment instead.')
        ->assertSee('<a href="/areas/inbound">', escape: false)
        ->assertSee('action="/search"', escape: false)
        ->assertDontSee('Press Print on the shipment.');
});

it('leaves out the Product Area link when the area has no Published Article to show', function () {
    content()
        ->article('print-a-dock-sheet', [
            'product_area' => 'Returns',
            'status' => 'Retired',
            'what_changed' => 'First Mile Manager no longer prints dock sheets.',
        ])
        ->publish();

    $this->get('/articles/print-a-dock-sheet')
        ->assertStatus(410)
        ->assertDontSee('/areas/', escape: false)
        ->assertSee('action="/search"', escape: false);
});

it('marks the retired notice "noindex", even once the help centre has gone public', function () {
    config(['help.going_public' => true]);
    content()
        ->article('print-a-dock-sheet', ['status' => 'Retired', 'what_changed' => 'First Mile Manager no longer prints dock sheets.'])
        ->article('receive-at-the-dock')
        ->publish();

    $this->get('/articles/print-a-dock-sheet')
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertSee('<meta name="robots" content="noindex">', escape: false);

    $this->get('/articles/receive-at-the-dock')
        ->assertHeaderMissing('X-Robots-Tag')
        ->assertDontSee('<meta name="robots"', escape: false);
});

it('waits for a Draft to be published before its former addresses redirect to it', function () {
    $content = content()
        ->article('book-in-a-delivery', ['status' => 'Draft', 'former_addresses' => ['/articles/book-a-slot']])
        ->publish();

    $this->get('/articles/book-a-slot')->assertNotFound();

    $content->article('book-in-a-delivery', ['former_addresses' => ['/articles/book-a-slot']])->publish();

    $this->get('/articles/book-a-slot')->assertStatus(301)->assertRedirect('/articles/book-in-a-delivery');
});

it('opens an Article at its top when a link names a Section Name that was removed', function () {
    // The link was to /articles/receive-at-the-dock#at-the-dock-door. The Section was reworded
    // and its Section Name removed, so no element on the page has that id any more and the
    // browser opens the Article at its top. Only Section Names and the layout's own landmark
    // give elements ids, so a removed Section Name can never land somewhere else.
    content()
        ->article('receive-at-the-dock', [], <<<'MD'
            Scan each product in at the dock.

            ## Scan at the door

            Scan the pallet label first.
            MD)
        ->publish();

    $page = $this->get('/articles/receive-at-the-dock')->assertOk()->getContent();

    preg_match_all('/\sid="([^"]*)"/', (string) $page, $ids);
    expect($ids[1])->toBe(['main']);
});

it('still shows page not found for an address that never existed', function () {
    content()
        ->article('receive-at-the-dock', ['former_addresses' => ['/articles/receive-a-shipment']])
        ->publish();

    $this->get('/articles/receive-a-pallet')->assertNotFound()->assertSee('Page not found');
    $this->get('/areas/receive-a-shipment')->assertNotFound();
});
