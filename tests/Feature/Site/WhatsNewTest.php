<?php

use Tests\Support\ReaderPage;

/*
| What's new (spec #30, user stories 17, 59 and 147; plan, "Release Notes"): one Release Note per
| FMM Release at /whats-new/<yyyy-mm-dd>, listed newest first at /whats-new, its changes
| grouped by Product Area with the lines that change what clients see marked "Affects your
| clients". Release Notes are for Staff, have no feedback and are never searched.
*/

it('lists the Release Notes newest first, each by its summary and date', function () {
    content()
        ->releaseNote('2026-11-14', ['summary' => 'Delivery numbers now carry their warehouse.'])
        ->releaseNote('2026-12-02', ['summary' => 'Warehouse Managers set up carriers.'])
        ->releaseNote('2026-10-30', ['summary' => 'The help centre is open.'])
        ->publish();

    $page = ReaderPage::of($this->get('/whats-new'));

    expect($page->text('h1'))->toBe('What’s new')
        ->and($page->links('.release-notes a'))->toBe([
            ['Warehouse Managers set up carriers. 2 December 2026', '/whats-new/2026-12-02'],
            ['Delivery numbers now carry their warehouse. 14 November 2026', '/whats-new/2026-11-14'],
            ['The help centre is open. 30 October 2026', '/whats-new/2026-10-30'],
        ])
        ->and($page->text('title'))->toBe('What’s new | First Mile Manager Help centre');
});

it('shows a Release Note at its dated address, titled by its date', function () {
    content()
        ->releaseNote('2026-11-14', ['summary' => 'Delivery numbers now carry their warehouse.'], "Thank you for your patience while we moved.\n")
        ->publish();

    $page = ReaderPage::of($this->get('/whats-new/2026-11-14'));

    expect($page->text('h1'))->toBe('What’s new: 14 November 2026')
        ->and($page->text('title'))->toBe('What’s new: 14 November 2026 | First Mile Manager Help centre')
        ->and($page->text('.release-note .summary'))->toBe('Delivery numbers now carry their warehouse.')
        ->and($page->text('.release-note .release-intro'))->toBe('Thank you for your patience while we moved.')
        ->and($page->links('.crumbs a'))->toBe([['Help centre', '/'], ['What’s new', '/whats-new']])
        ->and($page->attributes('meta[name="description"]')[0]['content'] ?? null)->toBe('Delivery numbers now carry their warehouse.');
});

it('groups a Release Note\'s changes by Product Area in the app\'s menu order, marking those that affect clients', function () {
    content()
        ->productAreas(['Team' => 'team.cluster.title', 'Warehouses' => 'warehouses.cluster.title', 'Inbound' => 'inbound.cluster.title'])
        ->article('book-in-a-shipment', ['title' => 'Book in a shipment'])
        ->article('add-a-warehouse-and-zones', ['title' => 'Add a warehouse and its zones', 'product_area' => 'Warehouses'])
        ->releaseNote('2026-11-14', ['changes' => [
            ['product_area' => 'Inbound', 'team_roles' => ['Admin', 'Warehouse Operative'], 'affects_clients' => true,
                'articles' => ['book-in-a-shipment'], 'text' => 'Delivery numbers now carry the warehouse they belong to, such as `INB-W1-000023`.'],
            ['product_area' => 'Warehouses', 'team_roles' => ['Admin'], 'affects_clients' => false,
                'articles' => ['add-a-warehouse-and-zones'], 'text' => 'Each warehouse has a Warehouse Code of 1 to 4 capital letters or digits.'],
            ['product_area' => 'Inbound', 'team_roles' => ['Warehouse Operative'], 'affects_clients' => false,
                'text' => 'The dock receiving screen refuses a [delivery number](article:book-in-a-shipment).'],
        ]])
        ->publish();

    $page = ReaderPage::of($this->get('/whats-new/2026-11-14'));

    expect($page->texts('.release-area h2'))->toBe(['Warehouses', 'Inbound'])
        ->and($page->texts('.release-area .change'))->toBe([
            'Each warehouse has a Warehouse Code of 1 to 4 capital letters or digits. For: Admin Read: Add a warehouse and its zones',
            'Delivery numbers now carry the warehouse they belong to, such as INB-W1-000023. For: Admin, Warehouse Operative Affects your clients Read: Book in a shipment',
            'The dock receiving screen refuses a delivery number. For: Warehouse Operative',
        ])
        ->and($page->texts('.change .affects-clients'))->toBe(['Affects your clients'])
        ->and($page->links('.release-area .change a'))->toBe([
            ['Add a warehouse and its zones', '/articles/add-a-warehouse-and-zones'],
            ['Book in a shipment', '/articles/book-in-a-shipment'],
            ['delivery number', '/articles/book-in-a-shipment'],
        ]);
});

it('gives Release Notes no feedback and no "Still stuck?"', function () {
    content()->releaseNote('2026-11-14')->publish();

    $page = ReaderPage::of($this->get('/whats-new/2026-11-14'));

    expect($page->has('.feedback'))->toBeFalse()
        ->and($page->has('.still-stuck'))->toBeFalse()
        ->and($page->has('form[method="post"]'))->toBeFalse();
    $this->post('/feedback/2026-11-14', ['answer' => 'yes'], ['Sec-Fetch-Site' => 'same-origin'])->assertNotFound();
});

it('never searches Release Notes', function () {
    content()
        ->article('book-in-a-shipment', ['title' => 'Book in a shipment'])
        ->releaseNote('2026-11-14', ['summary' => 'Quokkas now guard the dock.', 'changes' => [['text' => 'Every quokka checks the paperwork.']]])
        ->publish();

    $page = ReaderPage::of($this->get('/search?q=quokka'));

    expect($page->has('.results .result'))->toBeFalse()
        ->and($page->text('.search-none'))->toContain('No results');
});

it('is page not found for a date with no Release Note', function () {
    content()->releaseNote('2026-11-14')->publish();

    $this->get('/whats-new/2026-11-15')->assertNotFound();
    $this->get('/whats-new/14-11-2026')->assertNotFound();
});

it('says so while there is no Release Note yet', function () {
    content()->article('book-in-a-shipment')->publish();

    $page = ReaderPage::of($this->get('/whats-new'));

    expect($page->has('.release-notes'))->toBeFalse()
        ->and($page->text('.listing-empty'))->toContain('No Release Notes yet');
});

it('links What’s new from every page\'s header', function () {
    content()->article('book-in-a-shipment')->releaseNote('2026-11-14')->publish();

    expect(ReaderPage::of($this->get('/articles/book-in-a-shipment'))->links('.head-nav a'))
        ->toContain(['What’s new', '/whats-new']);
});
