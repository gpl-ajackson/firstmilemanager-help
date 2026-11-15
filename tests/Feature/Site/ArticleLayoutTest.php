<?php

use Tests\Support\BrowserLayout;

/*
| Where an Article's parts sit on a wide screen and on a phone, laid out in headless Chromium
| (spec #30, user story 32).
*/

beforeEach(function () {
    content()
        ->article('receive-at-the-dock', ['related_articles' => ['mark-a-shipment-arrived', 'record-discrepancies']], <<<'MD'
            ## In short

            Open the shipment and select **Open Dock Terminal**. Scan `NEW-PAL`, then each product.

            ## Steps

            1. Go to **Inbound › Inbound Shipments** and open `INB-W1-00000012`.
            2. Select **Complete receiving**.
            MD)
        ->article('mark-a-shipment-arrived', ['title' => 'Mark a shipment arrived'])
        ->article('record-discrepancies', ['title' => 'Inbound discrepancies', 'type' => 'Concept'])
        ->publish();
});

it('shows related articles in a column beside the Article on a wide screen', function () {
    $layout = BrowserLayout::of($this->get('/articles/receive-at-the-dock'), width: 1280, selectors: ['.article', '.related']);

    $article = $layout->box('.article');
    $related = $layout->box('.related');

    expect($related['x'])->toBeGreaterThanOrEqual($article['x'] + $article['width'])
        ->and($related['y'])->toBeLessThan($article['y'] + $article['height'])
        ->and($layout->scrollWidth)->toBeLessThanOrEqual($layout->width);
});

it('shows related articles after the Article on a phone, with no sideways scrolling', function (int $width) {
    $layout = BrowserLayout::of($this->get('/articles/receive-at-the-dock'), width: $width, selectors: ['.article', '.related']);

    $article = $layout->box('.article');
    $related = $layout->box('.related');

    expect($related['y'])->toBeGreaterThanOrEqual($article['y'] + $article['height'])
        ->and($related['x'])->toBe($article['x'])
        ->and($layout->scrollWidth)->toBeLessThanOrEqual($layout->width);
})->with([390, 320]);
