<?php

use Tests\Support\ReaderPage;

it('shows a Published Article at its address with its title, summary and body', function () {
    content()
        ->article('receive-at-the-dock', [
            'title' => 'Receive a shipment with the Dock Receiving Terminal',
            'summary' => 'Scan each product into a container at the dock door, then complete receiving.',
        ], <<<'MD'
            ## Steps

            1. Go to **Inbound › Inbound Shipments** and open the shipment.
            2. Scan `NEW-PAL` to open a new pallet.
            MD)
        ->publish();

    $this->get('/articles/receive-at-the-dock')
        ->assertOk()
        ->assertSee('<h1>Receive a shipment with the Dock Receiving Terminal</h1>', escape: false)
        ->assertSee('Scan each product into a container at the dock door, then complete receiving.')
        ->assertSee('<h2>Steps</h2>', escape: false)
        ->assertSee('<strong>Inbound › Inbound Shipments</strong>', escape: false)
        ->assertSee('<code>NEW-PAL</code>', escape: false);
});

it('never shows a Draft Article', function () {
    content()
        ->article('count-and-correct-stock', ['title' => 'Count stock and correct a quantity', 'status' => 'Draft'])
        ->publish();

    $this->get('/articles/count-and-correct-stock')
        ->assertNotFound()
        ->assertSee('Page not found')
        ->assertDontSee('Count stock and correct a quantity');
});

it('serves the page rendered at the last rebuild, not the file as it is now', function () {
    $content = content()->article('receive-at-the-dock', [], "Scan `NEW-PAL` first.\n")->publish();

    $content->article('receive-at-the-dock', [], "Scan `LOOSE` first.\n");

    $this->get('/articles/receive-at-the-dock')->assertSee('NEW-PAL')->assertDontSee('LOOSE');

    $content->publish();

    $this->get('/articles/receive-at-the-dock')->assertSee('LOOSE')->assertDontSee('NEW-PAL');
});

it('shows HTML a writer typed as text, never as markup', function () {
    content()
        ->article('receive-at-the-dock', [], "Type <script>alert(1)</script> and [a link](javascript:alert(1)).\n")
        ->publish();

    $response = $this->get('/articles/receive-at-the-dock')
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', escape: false)
        ->assertDontSee('javascript:', escape: false);
    // The page's only script is the site's own (public/js/site.js), never the writer's.
    expect(substr_count((string) $response->getContent(), '<script'))->toBe(1)
        ->and(array_map(fn (array $script): ?string => $script['src'] ?? null, ReaderPage::of($response)->attributes('script')))->toHaveCount(1);
});

it('marks the page with the Article\'s title and summary for browsers and search results', function () {
    content()
        ->article('receive-at-the-dock', [
            'title' => 'Receive a shipment at the dock',
            'summary' => 'Scan each product in at the dock door.',
        ])
        ->publish();

    $this->get('/articles/receive-at-the-dock')
        ->assertSee('<title>Receive a shipment at the dock | First Mile Manager Help centre</title>', escape: false)
        ->assertSee('<meta name="description" content="Scan each product in at the dock door.">', escape: false)
        ->assertSee('<html lang="en-GB">', escape: false);
});
