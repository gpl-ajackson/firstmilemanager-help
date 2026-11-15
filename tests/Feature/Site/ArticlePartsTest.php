<?php

use Tests\Support\ReaderPage;

/*
| The parts of an Article page by Article Type and Audience Tier (spec #30, user stories 18 to
| 39 and 85; the plan's Article Templates), as a reader sees them.
*/

it('puts the help home and the Product Area in the breadcrumb', function () {
    content()->article('move-a-container', ['product_area' => 'Stock Containers'])->publish();

    expect(ReaderPage::of($this->get('/articles/move-a-container'))->links('nav[aria-label="Breadcrumb"] a'))
        ->toBe([['Help centre', '/'], ['Stock Containers', '/areas/stock-containers']]);
});

it('shows the type, Product Area, Team Roles and Checked On under the title, labelling Team Roles by type', function (string $type, string $label) {
    content()
        ->article('the-article', [
            'type' => $type,
            'product_area' => 'Inbound',
            'team_roles' => ['Admin', 'Warehouse Operative'],
            'checked_on' => '2026-10-24',
        ])
        ->publish();

    expect(ReaderPage::of($this->get('/articles/the-article'))->text('.details'))
        ->toBe("{$type} Inbound {$label} Admin, Warehouse Operative Checked on 24 October 2026");
})->with([
    'How-to' => ['How-to', 'Who can do this:'],
    'Getting-started' => ['Getting-started', 'Who can do this:'],
    'Concept' => ['Concept', 'For:'],
    'Reference' => ['Reference', 'For:'],
    'Troubleshooting' => ['Troubleshooting', 'For:'],
    'FAQ' => ['FAQ', 'For:'],
]);

it('leaves Team Roles out of a Client-tier Article\'s details', function (string $type) {
    content()
        ->article('see-your-stock', ['type' => $type, 'audience_tier' => 'Client', 'product_area' => 'Inventory', 'checked_on' => '2026-10-24'])
        ->publish();

    expect(ReaderPage::of($this->get('/articles/see-your-stock'))->text('.details'))
        ->toBe("{$type} Inventory Checked on 24 October 2026");
})->with(['How-to', 'Getting-started', 'Concept', 'Reference', 'Troubleshooting', 'FAQ']);

it('shows no Team Roles anywhere on a Client-tier page, even ones its header data names', function () {
    content()
        ->article('follow-your-deliveries', ['audience_tier' => 'Client', 'team_roles' => ['Client User', 'Client Account Administrator']])
        ->publish();

    $this->get('/articles/follow-your-deliveries')
        ->assertOk()
        ->assertDontSee('Client User')
        ->assertDontSee('Client Account Administrator')
        ->assertDontSee('Who can do this');
});

it('ends every Client-tier Article by sending the client to their account manager', function () {
    content()
        ->article('follow-your-deliveries', ['audience_tier' => 'Client'], "## In short\n\nOpen **Inbound › Inbound Shipments**.\n")
        ->article('receive-at-the-dock')
        ->publish();

    expect(ReaderPage::of($this->get('/articles/follow-your-deliveries'))->text('.client-close'))
        ->toBe('Questions about your stock or deliveries go to your account manager.');
    $this->get('/articles/receive-at-the-dock')->assertDontSee('your account manager');
});

it('boxes "In short" at the top, with screen words in bold and codes in the mono face', function () {
    content()
        ->article('put-a-client-on-hold', [], <<<'MD'
            ## In short

            Go to **Clients › Lumen & Loom** and select **Place hold**. Scan `A1A1` to check.

            ## Before you start

            You need to be an Admin.
            MD)
        ->publish();

    $response = $this->get('/articles/put-a-client-on-hold');
    $page = ReaderPage::of($response);

    expect($page->text('.prose > :first-child'))
        ->toBe('In short Go to Clients › Lumen & Loom and select Place hold. Scan A1A1 to check.')
        ->and($page->has('.prose > .in-short'))->toBeTrue()
        ->and($page->text('.in-short strong'))->toBe('Clients › Lumen & Loom')
        ->and($page->text('.in-short code'))->toBe('A1A1')
        ->and($page->text('.in-short'))->not->toContain('Before you start');
});

it('gives a heading its Section Name as its fragment, and keeps it when the heading is reworded', function () {
    $content = content()
        ->article('stock-wont-go-into-this-location', [], "## The location is too small {#too-small}\n\nPick a bigger one.\n")
        ->publish();

    $page = ReaderPage::of($this->get('/articles/stock-wont-go-into-this-location'));
    expect($page->text('#too-small'))->toBe('The location is too small');

    $content->article('stock-wont-go-into-this-location', [], "## The container doesn't fit {#too-small}\n\nPick a bigger one.\n")->publish();

    $page = ReaderPage::of($this->get('/articles/stock-wont-go-into-this-location'));
    expect($page->text('#too-small'))->toBe("The container doesn't fit");
});

it('gives no fragment to a heading without a Section Name', function () {
    content()->article('move-a-container', [], "## In short\n\nMove it.\n\n## Steps\n\n1. Scan it.\n")->publish();

    $this->get('/articles/move-a-container')
        ->assertSee('<h2>Steps</h2>', escape: false)
        ->assertDontSee('<h2 id=', escape: false);
});

it('lets a table take the keyboard\'s focus, as it scrolls sideways on a narrow screen', function () {
    content()->article('client-statuses', [], "## In short\n\nFive statuses.\n\n| Work | Active | On Hold |\n| --- | --- | --- |\n| Book in deliveries | Yes | No |\n")->publish();

    $page = ReaderPage::of($this->get('/articles/client-statuses'));
    expect($page->attributes('.prose table'))->toBe([['tabindex' => '0']]);
});

it('lists a Troubleshooting Article\'s messages under "What you see", from its header data, with Placeholders marked', function () {
    content()
        ->article('cant-receive-for-this-client', [
            'type' => 'Troubleshooting',
            'messages' => [
                '[client] is on hold, so no deliveries can be booked in for it. Ask [contact].',
                'Not open for receiving',
            ],
        ], <<<'MD'
            ## In short

            The client is on hold. Ask an Admin to lift the hold.

            ## The client is on hold {#on-hold}

            An Admin placed a hold on the client.
            MD)
        ->publish();

    $response = $this->get('/articles/cant-receive-for-this-client');
    $page = ReaderPage::of($response);

    expect($page->texts('.prose h2'))->toBe(['In short', 'What you see', 'The client is on hold'])
        ->and($page->texts('.what-you-see blockquote'))->toBe([
            '[client] is on hold, so no deliveries can be booked in for it. Ask [contact].',
            'Not open for receiving',
        ])
        ->and($page->texts('.what-you-see var'))->toBe(['[client]', '[contact]']);
});

it('shows a Getting-started Article\'s place in the path and links to the next step after "You\'re done when…"', function () {
    content()
        ->article('get-started', ['type' => 'Getting-started', 'path_position' => 1, 'title' => 'Get started'])
        ->article('build-your-warehouse', ['type' => 'Getting-started', 'path_position' => 2, 'title' => 'Build your warehouse'], <<<'MD'
            ## In short

            You'll have one warehouse with its locations.

            ## You're done when…

            Your warehouse shows in **Warehouses**.
            MD)
        ->article('label-your-locations-draft', ['type' => 'Getting-started', 'path_position' => 3, 'status' => 'Draft'])
        ->article('add-your-first-client', ['type' => 'Getting-started', 'path_position' => 4, 'title' => 'Add your first client'])
        ->publish();

    $page = ReaderPage::of($this->get('/articles/build-your-warehouse'));

    expect($page->text('.path-place'))->toBe('Getting-started Path: step 2 of 3')
        ->and($page->text('.next-step'))->toBe('Next: step 3 of 3 Add your first client')
        ->and($page->links('.next-step a'))->toBe([['Add your first client', '/articles/add-your-first-client']])
        ->and($page->texts('.article > *'))->sequence(
            fn ($part) => $part->toContain('Build your warehouse'),
            fn ($part) => $part->toContain("You're done when… Your warehouse shows in Warehouses."),
            fn ($part) => $part->toStartWith('Next: step 3 of 3'),
            fn ($part) => $part->toBe('Did this answer your question? Yes No'),
            fn ($part) => $part->toStartWith('Still stuck?'),
        );
});

it('says the last Getting-started step ends the path', function () {
    content()
        ->article('get-started', ['type' => 'Getting-started', 'path_position' => 1])
        ->article('see-what-your-client-sees', ['type' => 'Getting-started', 'path_position' => 11])
        ->publish();

    $page = ReaderPage::of($this->get('/articles/see-what-your-client-sees'));

    expect($page->text('.path-place'))->toBe('Getting-started Path: step 2 of 2')
        ->and($page->text('.next-step'))->toBe('This is the last step of the Getting-started Path.')
        ->and($page->links('.next-step a'))->toBe([]);
});

it('shows no place in the path on other types', function () {
    content()->article('receive-at-the-dock')->publish();

    $page = ReaderPage::of($this->get('/articles/receive-at-the-dock'));

    expect($page->has('.path-place'))->toBeFalse()
        ->and($page->has('.next-step'))->toBeFalse();
});

it('lists related articles by title in the writer\'s order, leaving out any a reader can\'t open', function () {
    content()
        ->article('receive-at-the-dock', ['related_articles' => [
            'record-discrepancies', 'a-draft', 'mark-a-shipment-arrived', 'never-written', 'receive-at-the-dock',
        ]])
        ->article('mark-a-shipment-arrived', ['title' => 'Mark a shipment arrived', 'type' => 'How-to'])
        ->article('record-discrepancies', ['title' => 'Inbound discrepancies', 'type' => 'Concept'])
        ->article('a-draft', ['status' => 'Draft'])
        ->publish();

    $page = ReaderPage::of($this->get('/articles/receive-at-the-dock'));

    expect($page->text('.related h2'))->toBe('Related articles')
        ->and($page->links('.related a'))->toBe([
            ['Inbound discrepancies Concept', '/articles/record-discrepancies'],
            ['Mark a shipment arrived How-to', '/articles/mark-a-shipment-arrived'],
        ]);
});

it('lists only Client-tier related articles on a Client-tier Article', function () {
    content()
        ->article('follow-your-deliveries', ['audience_tier' => 'Client', 'related_articles' => ['see-your-stock', 'receive-at-the-dock']])
        ->article('see-your-stock', ['audience_tier' => 'Client', 'title' => 'See your stock'])
        ->article('receive-at-the-dock')
        ->publish();

    expect(ReaderPage::of($this->get('/articles/follow-your-deliveries'))->links('.related a'))
        ->toBe([['See your stock How-to', '/articles/see-your-stock']]);
});

it('leaves the related column out when no related article can be opened', function () {
    content()->article('receive-at-the-dock', ['related_articles' => ['never-written', 'not-yet']])->publish();

    expect(ReaderPage::of($this->get('/articles/receive-at-the-dock'))->has('.related'))->toBeFalse();
});
