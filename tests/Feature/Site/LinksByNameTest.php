<?php

use Tests\Support\ReaderPage;

/*
| Links by name (spec #30, user stories 33, 86 and 105; Further Notes 2). Writers link an
| Article, a Section or a term by name, never by address, and the page shows the address for
| the reader's tier. A link to a name that doesn't exist is plain text, and the rebuild lists it.
*/

it('turns a link to an Article Name into the Article\'s address', function () {
    content()
        ->article('receive-a-delivery', [], "Read [how to book one in](article:book-in-a-delivery) first.\n")
        ->article('book-in-a-delivery')
        ->publish();

    expect(ReaderPage::of($this->get('/articles/receive-a-delivery'))->links('.prose a'))
        ->toBe([['how to book one in', '/articles/book-in-a-delivery']]);
});

it('adds the Section\'s fragment to a link naming a Section', function () {
    content()
        ->article('receive-a-delivery', [], "Read [what to check](article:book-in-a-delivery#check-the-asn) first.\n")
        ->article('book-in-a-delivery', [], "## Check the ASN {#check-the-asn}\n\nLook at it.\n")
        ->publish();

    expect(ReaderPage::of($this->get('/articles/receive-a-delivery'))->links('.prose a'))
        ->toBe([['what to check', '/articles/book-in-a-delivery#check-the-asn']]);
});

it('shows a link to a missing Article as plain text and lists it with its file and line', function () {
    $content = content()
        ->file('articles/receive-a-delivery.md', <<<'MD'
            ---
            title: Receive a delivery
            summary: How to receive a delivery at the dock, in one sentence.
            type: How-to
            product_area: Inbound
            audience_tier: Staff
            owner: gpl-ajackson
            status: Published
            checked_on: 2026-10-24
            next_review_on: 2027-04-24
            team_roles: [Admin]
            source_references: [ADR-0001]
            related_articles:
              - example-concept
              - example-reference
            ---

            ## Before you start

            You need an ASN.
            Read [how to book one in](article:book-in-a-delivery) first.
            MD)
        ->article('example-concept', ['related_articles' => ['receive-a-delivery', 'example-reference']])
        ->article('example-reference', ['related_articles' => ['receive-a-delivery', 'example-concept']]);

    ['exitCode' => $exitCode, 'output' => $output] = $content->rebuild();

    $page = ReaderPage::of($this->get('/articles/receive-a-delivery'));
    expect($exitCode)->toBe(0)
        ->and($page->links('.prose a'))->toBe([])
        ->and($page->text('.prose'))->toContain('Read how to book one in first.')
        ->and($output)->toContain('1 link leads nowhere')
        ->and($output)->toContain('articles/receive-a-delivery.md:21: link "article:book-in-a-delivery": there is no Article named "book-in-a-delivery"');
});

it('sends a term link to the staff Glossary on a Staff page and to the Client Glossary on a Client-tier page', function () {
    content()
        ->glossaryEntry('stock-container')
        ->clientGlossaryEntry('stock-container')
        ->article('move-a-container', [], "Scan the [Stock Container](term:stock-container).\n")
        ->article('see-your-stock', ['audience_tier' => 'Client'], "Each [container](term:stock-container) holds stock.\n")
        ->publish();

    expect(ReaderPage::of($this->get('/articles/move-a-container'))->links('.prose a'))
        ->toBe([['Stock Container', '/glossary/stock-container']])
        ->and(ReaderPage::of($this->get('/articles/see-your-stock'))->links('.prose a'))
        ->toBe([['container', '/client-glossary/stock-container']]);
});

it('never sends a Client-tier page to staff help', function () {
    $content = content()
        ->glossaryEntry('hold-reason')
        ->article('book-in-a-delivery')
        ->article('see-your-stock', ['audience_tier' => 'Client'],
            "Your 3PL may give a [hold reason](term:hold-reason).\n\nSee [booking in](article:book-in-a-delivery), [Inbound](/areas/inbound) and [your words](/client-glossary).\n");

    $output = $content->rebuild()['output'];

    expect(ReaderPage::of($this->get('/articles/see-your-stock'))->links('.prose a'))->toBe([['your words', '/client-glossary']])
        ->and($output)->toContain('link "/areas/inbound": is not a Client-tier page; a Client-tier page links only to Client-tier pages')
        ->and($output)->toContain('link "term:hold-reason": there is no Client Glossary entry for "hold-reason"')
        ->and($output)->toContain('link "article:book-in-a-delivery": "book-in-a-delivery" is for Staff; a Client-tier page links only to Client-tier Articles');
});

it('lists a link to a missing Section or term, and to a Section of the same Article', function () {
    $content = content()
        ->article('book-in-a-delivery', [], "## Check the ASN {#check-the-asn}\n\nLook at it.\n")
        ->article('receive-a-delivery', [], <<<'MD'
            ## Steps {#steps}

            Go back to [the steps](#steps), or [the start](#start).
            Read [the checks](article:book-in-a-delivery#checks) and the [ASN](term:asn).
            MD);

    $output = $content->rebuild()['output'];

    expect(ReaderPage::of($this->get('/articles/receive-a-delivery'))->links('.prose a'))->toBe([['the steps', '#steps']])
        ->and($output)->toContain('link "#start": this Article has no Section named "start"')
        ->and($output)->toContain('link "article:book-in-a-delivery#checks": "book-in-a-delivery" has no Section named "checks"')
        ->and($output)->toContain('link "term:asn": there is no Glossary Entry for "asn"');
});

it('fills a link written with no words with the Article\'s title or the Section\'s heading', function () {
    content()
        ->article('book-in-a-delivery', ['title' => 'Book in a delivery'], "## Check the ASN {#check-the-asn}\n\nLook at it.\n")
        ->article('receive-a-delivery', [], "First [](article:book-in-a-delivery), then [](article:book-in-a-delivery#check-the-asn).\n")
        ->publish();

    expect(ReaderPage::of($this->get('/articles/receive-a-delivery'))->links('.prose a'))->toBe([
        ['Book in a delivery', '/articles/book-in-a-delivery'],
        ['Check the ASN', '/articles/book-in-a-delivery#check-the-asn'],
    ]);
});

it('links a Published page only to Published Articles, while a Draft may link to other Drafts', function () {
    $output = content()
        ->article('new-feature', ['status' => 'Draft'], "See [the other draft](article:other-draft).\n")
        ->article('other-draft', ['status' => 'Draft'])
        ->article('old-way', ['status' => 'Retired', 'what_changed' => 'First Mile Manager does this for you now.'])
        ->article('receive-a-delivery', [], "See [the new feature](article:new-feature) and [the old way](article:old-way).\n")
        ->rebuild()['output'];

    expect(ReaderPage::of($this->get('/articles/receive-a-delivery'))->links('.prose a'))->toBe([])
        ->and($output)->toContain('link "article:new-feature": "new-feature" is a Draft, so readers can\'t open it yet')
        ->and($output)->toContain('link "article:old-way": "old-way" is Retired; link the Article that replaces it')
        ->and($output)->not->toContain('article:other-draft');
});

it('lists help centre pages linked by address or in a form it doesn\'t know, and leaves web addresses and other pages alone', function () {
    $content = content()
        ->article('book-in-a-delivery')
        ->article('receive-a-delivery', [], <<<'MD'
            See [booking in](/articles/book-in-a-delivery), [the term](/glossary/asn) or [this](book-in-a-delivery.md).
            Also [Inbound](/areas/inbound), [the Glossary](/glossary), [the demo](https://demo.firstmilemanager.co.uk) and [email](mailto:hello@firstmilemanager.co.uk).
            MD);

    $output = $content->rebuild()['output'];

    expect(ReaderPage::of($this->get('/articles/receive-a-delivery'))->links('.prose a'))->toBe([
        ['Inbound', '/areas/inbound'],
        ['the Glossary', '/glossary'],
        ['the demo', 'https://demo.firstmilemanager.co.uk'],
        ['email', 'mailto:hello@firstmilemanager.co.uk'],
    ])
        ->and($output)->toContain('link "/articles/book-in-a-delivery": link a page of the help centre by name, not by address')
        ->and($output)->toContain('link "/glossary/asn": link a page of the help centre by name, not by address')
        ->and($output)->toContain('link "book-in-a-delivery.md": is not a link by name');
});

it('lists related articles that lead nowhere for the page\'s readers, with their header data line', function () {
    $output = content()
        ->file('articles/see-your-stock.md', <<<'MD'
            ---
            title: See your stock
            summary: How a client sees their stock, in one sentence.
            type: How-to
            product_area: Inventory
            audience_tier: Client
            owner: gpl-ajackson
            status: Published
            checked_on: 2026-10-24
            next_review_on: 2027-04-24
            source_references: [ADR-0001]
            related_articles:
              - follow-your-deliveries
              - book-in-a-delivery
              - gone-missing
            ---

            Open your stock.
            MD)
        ->article('follow-your-deliveries', ['audience_tier' => 'Client', 'related_articles' => ['see-your-stock', 'book-in-a-delivery']])
        ->article('book-in-a-delivery', ['related_articles' => ['see-your-stock', 'follow-your-deliveries']])
        ->rebuild()['output'];

    expect($output)
        ->toContain('articles/see-your-stock.md:14: related_articles "book-in-a-delivery": "book-in-a-delivery" is for Staff')
        ->toContain('articles/see-your-stock.md:15: related_articles "gone-missing": there is no Article named "gone-missing"');
});

it('lists nothing for a Retired Article, whose body no reader sees', function () {
    $output = content()
        ->article('old-way', ['status' => 'Retired', 'what_changed' => 'First Mile Manager does this for you now.'], "See [gone](article:gone).\n")
        ->rebuild()['output'];

    expect($output)->not->toContain('lead');
});

it('lists a path to a help centre page that doesn\'t exist, and a web address written without its scheme', function () {
    $content = content()
        ->article('book-in-a-delivery', ['product_area' => 'Inbound'])
        ->article('count-stock', ['product_area' => 'Inventory', 'status' => 'Draft'])
        ->releaseNote('2026-10-01')
        ->article('receive-a-delivery', [], <<<'MD'
            See [Inbound](/areas/inbound), [the owner path](/roles/admin), [the notes](/whats-new/2026-10-01) and [search](/search?q=asn#top).
            Not [Inbound](/areas/inbund), [Inventory](/areas/inventory), [the nobody path](/roles/nobody), [old notes](/whats-new/2020-01-01), [a page](/getting-started/more) or [elsewhere](//example.com/x).
            MD);

    $output = $content->rebuild()['output'];

    expect(ReaderPage::of($this->get('/articles/receive-a-delivery'))->links('.prose a'))->toBe([
        ['Inbound', '/areas/inbound'],
        ['the owner path', '/roles/admin'],
        ['the notes', '/whats-new/2026-10-01'],
        ['search', '/search?q=asn#top'],
    ])
        ->and($output)->toContain('link "/areas/inbund": there is no page at /areas/inbund')
        ->and($output)->toContain('link "/areas/inventory": /areas/inventory has no Published Article yet, so readers can\'t open it')
        ->and($output)->toContain('link "/roles/nobody": there is no page at /roles/nobody')
        ->and($output)->toContain('link "/whats-new/2020-01-01": there is no page at /whats-new/2020-01-01')
        ->and($output)->toContain('link "/getting-started/more": there is no page at /getting-started/more')
        ->and($output)->toContain('link "//example.com/x": write a web address in full, starting https://');
});

it('lets a Draft link the Product Area its Drafts will open', function () {
    $output = content()
        ->article('count-stock', ['product_area' => 'Inventory', 'status' => 'Draft'], "See [Inventory](/areas/inventory).\n")
        ->article('book-in-a-delivery')
        ->rebuild()['output'];

    expect($output)->not->toContain('/areas/inventory');
});
