<?php

use Tests\Support\BrowserLayout;
use Tests\Support\ReaderPage;

/*
| The Glossary and Client Glossary (spec #30, user stories 33, 46 and 75 to 82; plan, "The
| Glossary"). A reader with no warehouse knowledge looks up a word and goes from it to a task:
| each Glossary Entry says what the term is in a sentence or two, what else it is called, where
| it is seen, what each value means for a status, and which Articles use it.
*/

it('shows a Glossary Entry at its address with every part', function () {
    content()
        ->glossaryEntry('location', [
            'other_names' => ['bin', 'slot'],
            'where_you_see_it' => 'On the Warehouses menu, and on every label you scan.',
        ], "A **Location** is one place in the warehouse where stock is kept.\n")
        ->publish();

    $page = ReaderPage::of($this->get('/glossary/location')->assertOk());

    expect($page->text('h1'))->toBe('Location')
        ->and($page->text('.entry-also'))->toBe('Also called: bin, slot.')
        ->and($page->text('.entry-body'))->toBe('A Location is one place in the warehouse where stock is kept.')
        ->and($page->text('.entry-where'))->toBe('Where you’ll see it On the Warehouses menu, and on every label you scan.')
        ->and($page->text('title'))->toBe('Location | First Mile Manager Help centre');
});

it('lists a status\'s values each with what it means, linking a value that has its own entry', function () {
    content()
        ->glossaryEntry('client-status', ['status_values' => [
            ['value' => 'Active', 'meaning' => 'Deliveries and orders go ahead as normal.'],
            ['value' => 'On Hold', 'meaning' => 'New deliveries and orders are paused.'],
        ]])
        ->glossaryEntry('on-hold')
        ->publish();

    $page = ReaderPage::of($this->get('/glossary/client-status'));

    expect($page->texts('.entry-values dt'))->toBe(['Active', 'On Hold'])
        ->and($page->texts('.entry-values dd'))->toBe(['Deliveries and orders go ahead as normal.', 'New deliveries and orders are paused.'])
        ->and($page->links('.entry-values a'))->toBe([['On Hold', '/glossary/on-hold']]);
});

it('applies the bold and other inline formatting in where you\'ll see it and each value\'s meaning', function () {
    content()
        ->glossaryEntry('code-style', [
            'where_you_see_it' => 'As **Code style** on the first step of **Batch Generate Locations**.',
            'status_values' => [
                ['value' => 'Letters', 'meaning' => 'Codes run **A**, **B**, **C** <b>and on</b>.'],
                ['value' => 'Numbers', 'meaning' => 'Codes run 1, 2, 3.'],
            ],
        ])
        ->publish();

    $response = $this->get('/glossary/code-style')->assertOk();
    $page = ReaderPage::of($response);

    expect($page->texts('.entry-where strong'))->toBe(['Code style', 'Batch Generate Locations'])
        ->and($page->text('.entry-where p'))->toBe('As Code style on the first step of Batch Generate Locations.')
        ->and($page->texts('.entry-values dd strong'))->toBe(['A', 'B', 'C'])
        ->and($page->has('.entry-values dd b'))->toBeFalse();
    $response->assertDontSee('**', false);
});

it('leaves out the parts an entry has nothing for, and never asks for feedback', function () {
    content()->glossaryEntry('zone')->publish();

    $page = ReaderPage::of($this->get('/glossary/zone'));

    expect($page->has('.entry-also'))->toBeFalse()
        ->and($page->has('.entry-values'))->toBeFalse()
        ->and($page->has('.entry-articles'))->toBeFalse()
        ->and($page->has('form'))->toBeFalse()
        ->and($page->has('.still-stuck'))->toBeFalse()
        ->and($page->text('main'))->not->toContain('Did this answer your question?');
});

it('lists the staff Glossary A to Z under each letter', function () {
    content()
        ->glossaryEntry('zone')
        ->glossaryEntry('aisle')
        ->glossaryEntry('location', ['other_names' => ['bin']])
        ->glossaryEntry('advanced-shipping-notice-asn', ['term' => 'Advanced Shipping Notice (ASN)'])
        ->glossaryEntry('location-code')
        ->clientGlossaryEntry('account')
        ->publish();

    $page = ReaderPage::of($this->get('/glossary'));

    expect($page->text('h1'))->toBe('Glossary')
        ->and($page->texts('.glossary-letter h2'))->toBe(['A', 'L', 'Z'])
        ->and($page->links('.glossary-letter a'))->toBe([
            ['Advanced Shipping Notice (ASN)', '/glossary/advanced-shipping-notice-asn'],
            ['Aisle', '/glossary/aisle'],
            ['Location Also called bin', '/glossary/location'],
            ['Location Code', '/glossary/location-code'],
            ['Zone', '/glossary/zone'],
        ]);
});

it('lists exactly the Published Articles that link to the term as the Articles that use it', function () {
    content()
        ->glossaryEntry('stock-container')
        ->glossaryEntry('zone')
        ->clientGlossaryEntry('stock-container')
        ->article('move-a-container', ['title' => 'Move a container'], "Scan the [Stock Container](term:stock-container).\n")
        ->article('label-a-container', ['title' => 'Label a container'], "Print a label for the [container](term:stock-container).\n")
        ->article('build-a-zone', ['title' => 'Build a zone'], "Add a [Zone](term:zone). A Stock Container sits in it.\n")
        ->article('new-container-screen', ['status' => 'Draft'], "The new [container](term:stock-container) screen.\n")
        ->article('old-container-screen', ['status' => 'Retired', 'what_changed' => 'It moved.'], "The [container](term:stock-container).\n")
        ->article('see-your-stock', ['audience_tier' => 'Client', 'title' => 'See your stock'], "Each [container](term:stock-container) holds stock.\n")
        ->publish();

    expect(ReaderPage::of($this->get('/glossary/stock-container'))->links('.entry-articles a'))->toBe([
        ['Label a container How-to', '/articles/label-a-container'],
        ['Move a container How-to', '/articles/move-a-container'],
    ])
        ->and(ReaderPage::of($this->get('/client-glossary/stock-container'))->links('.entry-articles a'))->toBe([
            ['See your stock How-to', '/articles/see-your-stock'],
        ]);
});

it('redirects a renamed term\'s old address for good, and its old name is one it is also called', function () {
    content()
        ->glossaryEntry('stock-container', ['former_names' => ['Container', 'Storage Unit'], 'other_names' => ['pallet', 'container']])
        ->clientGlossaryEntry('stock-container', ['former_names' => ['Container']])
        ->publish();

    $this->get('/glossary/storage-unit?from=search')->assertStatus(301)->assertRedirect('/glossary/stock-container?from=search');
    $this->get('/glossary/container')->assertStatus(301)->assertRedirect('/glossary/stock-container');
    $this->get('/client-glossary/container')->assertStatus(301)->assertRedirect('/client-glossary/stock-container');
    expect(ReaderPage::of($this->get('/glossary/stock-container'))->text('.entry-also'))->toBe('Also called: pallet, container, Storage Unit.');
});

it('links only the first use of a term in an Article', function () {
    content()
        ->glossaryEntry('stock-container')
        ->glossaryEntry('zone')
        ->article('move-a-container', [], <<<'MD'
            ## In short

            Scan the [Stock Container](term:stock-container), then the [Zone](term:zone).

            ## Steps

            1. Pick up the [container](term:stock-container).
            2. Scan the [](term:stock-container) again in the [zone](term:zone).
            MD)
        ->publish();

    $page = ReaderPage::of($this->get('/articles/move-a-container'));

    expect($page->links('.prose a'))->toBe([
        ['Stock Container', '/glossary/stock-container'],
        ['Zone', '/glossary/zone'],
    ])
        ->and($page->text('.prose ol'))->toBe('Pick up the container. Scan the Stock Container again in the zone.');
});

it('fills a term link written with no words with the term', function () {
    content()
        ->glossaryEntry('stock-container')
        ->article('move-a-container', [], "Scan the [](term:stock-container).\n")
        ->publish();

    expect(ReaderPage::of($this->get('/articles/move-a-container'))->links('.prose a'))
        ->toBe([['Stock Container', '/glossary/stock-container']]);
});

it('shows the Client Glossary A to Z under For your clients, without a word of Stockist', function () {
    content()
        ->clientGlossaryEntry('on-hold', [], "Your 3PL has paused new deliveries and orders on your account.\n")
        ->clientGlossaryEntry('client-account')
        ->glossaryEntry('hold-reason')
        ->publish();

    $listing = ReaderPage::of($this->get('/client-glossary'));
    $entry = ReaderPage::of($this->get('/client-glossary/on-hold'));

    expect($listing->text('h1'))->toBe('Client Glossary')
        ->and($listing->links('.crumbs a'))->toBe([['Help centre', '/'], ['For your clients', '/for-your-clients']])
        ->and($listing->links('.glossary-letter a'))->toBe([
            ['Client Account', '/client-glossary/client-account'],
            ['On Hold', '/client-glossary/on-hold'],
        ])
        ->and($listing->text('main'))->not->toContain('Stockist')
        ->and($entry->links('.crumbs a'))->toBe([['Help centre', '/'], ['For your clients', '/for-your-clients'], ['Client Glossary', '/client-glossary']])
        ->and($entry->text('.entry-body'))->toBe('Your 3PL has paused new deliveries and orders on your account.');
});

it('links a client entry only to Client-tier Articles and Client Glossary entries', function () {
    $content = content()
        ->glossaryEntry('hold-reason')
        ->glossaryEntry('dock-override')
        ->glossaryEntry('on-hold')
        ->clientGlossaryEntry('on-hold')
        ->clientGlossaryEntry('client-status', ['status_values' => [
            ['value' => 'On Hold', 'meaning' => 'Your 3PL has paused new deliveries.'],
            ['value' => 'Dock Override', 'meaning' => 'Never shown to clients; here only to prove it isn\'t linked.'],
        ]], <<<'MD'
            What your account's status means. See [your account](article:your-account), [on hold](term:on-hold),
            [the reason](term:hold-reason) and [booking in](article:book-in-a-delivery).
            MD)
        ->article('your-account', ['audience_tier' => 'Client', 'title' => 'Your account'])
        ->article('book-in-a-delivery');

    $output = $content->rebuild()['output'];
    $page = ReaderPage::of($this->get('/client-glossary/client-status'));

    expect($page->links('.entry-body a'))->toBe([
        ['your account', '/articles/your-account'],
        ['on hold', '/client-glossary/on-hold'],
    ])
        ->and($page->links('.entry-values a'))->toBe([['On Hold', '/client-glossary/on-hold']])
        ->and($output)->toContain('client-glossary/client-status.md:16: link "term:hold-reason": there is no Client Glossary entry for "hold-reason"')
        ->and($output)->toContain('client-glossary/client-status.md:16: link "article:book-in-a-delivery": "book-in-a-delivery" is for Staff; a Client-tier page links only to Client-tier Articles');
});

it('never shows a staff-only term to clients: no client entry, no listing, no link', function () {
    content()
        ->glossaryEntry('hold-reason')
        ->clientGlossaryEntry('on-hold')
        ->article('your-account', ['audience_tier' => 'Client'], "Your 3PL may record a [hold reason](term:hold-reason).\n")
        ->publish();

    $this->get('/glossary/hold-reason')->assertOk();
    $this->get('/client-glossary/hold-reason')->assertNotFound();
    expect(ReaderPage::of($this->get('/client-glossary'))->text('main'))->not->toContain('Hold Reason')
        ->and(ReaderPage::of($this->get('/articles/your-account'))->links('.prose a'))->toBe([]);
});

it('shows page not found for a term with no entry', function () {
    content()->glossaryEntry('zone')->publish();

    $this->get('/glossary/bay')->assertNotFound();
    $this->get('/client-glossary/zone')->assertNotFound();
});

it('lays the Glossary and an entry out with no sideways scrolling on a phone or a wide screen', function (string $address, int $width) {
    content()
        ->glossaryEntry('advanced-shipping-notice-asn', [
            'term' => 'Advanced Shipping Notice (ASN)',
            'other_names' => ['pre-advice', 'delivery notification', 'inbound manifest notification'],
            'status_values' => [
                ['value' => 'Expected', 'meaning' => 'The delivery is on its way and can be booked in.'],
                ['value' => 'Received', 'meaning' => 'Everything on it has been counted in at the dock.'],
            ],
        ])
        ->article('receive-at-the-dock', [], "Check the [ASN](term:advanced-shipping-notice-asn).\n")
        ->publish();

    $layout = BrowserLayout::of($this->get($address), width: $width, selectors: ['main']);

    expect($layout->scrollWidth)->toBeLessThanOrEqual($layout->width);
})->with(['/glossary', '/glossary/advanced-shipping-notice-asn'])->with([320, 390, 1280]);
