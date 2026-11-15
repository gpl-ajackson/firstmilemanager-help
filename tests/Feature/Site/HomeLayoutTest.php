<?php

use Tests\Support\BrowserLayout;

/*
| Where the help home's blocks sit on a desktop and on a phone, laid out in headless Chromium
| (spec #30, user stories 11 and 89; plan, "How the pages look"), and that no listing scrolls
| sideways on a handheld.
*/

const HOME_BLOCKS = ['.home-search', '.home-roles', '.home-path', '.home-areas', '.home-clients', '.home-whats-new', '.home-lookup', '.home-new'];

beforeEach(function () {
    content()
        ->article('get-started', ['title' => 'Welcome: what you’ll set up', 'type' => 'Getting-started', 'path_position' => 1, 'product_area' => 'Team'])
        ->article('get-started-team', ['title' => 'Set up your team and give each person their Team Role', 'type' => 'Getting-started', 'path_position' => 2, 'product_area' => 'Team'])
        ->article('get-started-warehouse', ['title' => 'Build your warehouse', 'type' => 'Getting-started', 'path_position' => 3, 'product_area' => 'Warehouses'])
        ->article('receive-at-the-dock', ['title' => 'Receive a shipment with the Dock Receiving Terminal', 'team_roles' => ['Admin', 'Warehouse Operative']])
        ->article('stock-wont-fit', ['title' => 'Stock won’t go into a location', 'type' => 'Troubleshooting', 'product_area' => 'Stock Containers'])
        ->article('seeing-your-stock', ['title' => 'Seeing your stock', 'audience_tier' => 'Client', 'product_area' => 'Inventory'])
        ->clientGlossaryEntry('advanced-shipping-notice-asn', ['term' => 'Advanced Shipping Notice (ASN)'])
        ->releaseNote('2026-11-14', ['summary' => 'Delivery numbers now carry the warehouse they belong to, such as INB-W1-000023.', 'changes' => [
            ['affects_clients' => true, 'team_roles' => ['Admin', 'Warehouse Operative'], 'articles' => ['receive-at-the-dock'],
                'text' => 'Delivery numbers now carry the warehouse they belong to, such as `INB-W1-000023`, so a delivery\'s number tells you where it goes.'],
            ['product_area' => 'Warehouses', 'text' => 'Each warehouse has a Warehouse Code of 1 to 4 capital letters or digits.'],
        ]])
        ->publish();
});

/**
 * @param  list<string>  $selectors
 * @return list<string> The selectors in the order a reader meets them: top to bottom, then left to right.
 */
function readingOrder(BrowserLayout $layout, array $selectors): array
{
    usort($selectors, fn (string $a, string $b): int => [round($layout->box($a)['y']), $layout->box($a)['x']] <=> [round($layout->box($b)['y']), $layout->box($b)['x']]);

    return $selectors;
}

it('shows the home blocks in the plan\'s order on a desktop, For your clients beside What\'s new and the ways to look something up below both', function () {
    $layout = BrowserLayout::of($this->get('/'), width: 1280, selectors: HOME_BLOCKS);
    $clients = $layout->box('.home-clients');
    $whatsNew = $layout->box('.home-whats-new');
    $lookup = $layout->box('.home-lookup');

    expect(readingOrder($layout, HOME_BLOCKS))->toBe(HOME_BLOCKS)
        ->and($whatsNew['x'])->toBeGreaterThanOrEqual($clients['x'] + $clients['width'])
        ->and(round($whatsNew['y']))->toBe(round($clients['y']))
        ->and($lookup['y'])->toBeGreaterThanOrEqual(max($clients['y'] + $clients['height'], $whatsNew['y'] + $whatsNew['height']))
        ->and($lookup['x'])->toBe($clients['x'])
        ->and($lookup['width'])->toBeGreaterThanOrEqual($whatsNew['x'] + $whatsNew['width'] - $clients['x'] - 1)
        ->and($layout->scrollWidth)->toBeLessThanOrEqual($layout->width);
});

it('stacks the home blocks in one column in the same order on a phone, with no sideways scrolling', function (int $width) {
    $layout = BrowserLayout::of($this->get('/'), width: $width, selectors: HOME_BLOCKS);

    $previous = null;
    foreach (array_slice(HOME_BLOCKS, 1) as $block) {
        $box = $layout->box($block);
        expect($box['width'])->toBeLessThanOrEqual($width);
        if ($previous !== null) {
            expect($box['y'])->toBeGreaterThanOrEqual($previous['y'] + $previous['height'])
                ->and($box['x'])->toBe($previous['x']);
        }
        $previous = $box;
    }
    expect($layout->scrollWidth)->toBeLessThanOrEqual($layout->width);
})->with([390, 320]);

it('never scrolls sideways on a listing', function (string $address, int $width) {
    $layout = BrowserLayout::of($this->get($address), width: $width, selectors: ['main']);

    expect($layout->scrollWidth)->toBeLessThanOrEqual($layout->width);
})->with(['/roles/admin', '/getting-started', '/areas/inbound', '/for-your-clients', '/troubleshooting', '/whats-new', '/whats-new/2026-11-14'])
    ->with([320, 390, 1280]);
