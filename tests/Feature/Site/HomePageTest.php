<?php

use Tests\Support\FixtureContent;
use Tests\Support\ReaderPage;

/*
| The help home in the Signboard layout (spec #30, user stories 1 to 14; plan, "The home page"
| and "How the pages look"): search first, then the Role Paths, the Getting-started Path, the
| Product Areas, For your clients and What's new side by side, the ways to look something up,
| and "New to First Mile Manager?" last.
*/

/** A small help centre: a three-step path, Articles in three areas, a Draft-only area, client help and four Release Notes. */
function aSmallHelpCentre(): FixtureContent
{
    return content()
        ->productAreas(['Team' => 'team.cluster.title', 'Warehouses' => 'warehouses.cluster.title', 'Products' => 'products.cluster.title', 'Inbound' => 'inbound.cluster.title'])
        ->article('get-started', ['title' => 'Welcome: what you’ll set up', 'type' => 'Getting-started', 'path_position' => 1, 'product_area' => 'Team'])
        ->article('get-started-warehouse', ['title' => 'Build your warehouse', 'type' => 'Getting-started', 'path_position' => 3, 'product_area' => 'Warehouses'])
        ->article('get-started-team', ['title' => 'Set up your team', 'type' => 'Getting-started', 'path_position' => 2, 'product_area' => 'Team'])
        ->article('get-started-labels', ['title' => 'Label your locations', 'type' => 'Getting-started', 'path_position' => 4, 'product_area' => 'Warehouses', 'status' => 'Draft'])
        ->article('receive-at-the-dock', ['title' => 'Receive a shipment', 'team_roles' => ['Admin', 'Warehouse Operative']])
        ->article('book-in-a-shipment', ['title' => 'Book in a shipment'])
        ->article('add-a-warehouse-and-zones', ['title' => 'Add a warehouse and its zones', 'product_area' => 'Warehouses'])
        ->article('add-a-product', ['title' => 'Add a product', 'product_area' => 'Products', 'status' => 'Draft'])
        ->article('seeing-your-stock', ['title' => 'Seeing your stock', 'audience_tier' => 'Client', 'product_area' => 'Inventory'])
        ->article('signing-in', ['title' => 'Signing in for the first time', 'audience_tier' => 'Client', 'product_area' => 'Clients'])
        ->clientGlossaryEntry('on-hold')
        ->releaseNote('2026-10-30', ['summary' => 'The help centre is open.'])
        ->releaseNote('2026-12-02', ['summary' => 'Warehouse Managers set up carriers.'])
        ->releaseNote('2026-11-14', ['summary' => 'Delivery numbers now carry their warehouse.', 'changes' => [['affects_clients' => true]]])
        ->releaseNote('2026-11-21', ['summary' => 'Zones have a walk order.']);
}

it('shows every block in order, from search to "New to First Mile Manager?"', function () {
    aSmallHelpCentre()->publish();

    $page = ReaderPage::of($this->get('/'));

    expect($page->texts('main h1, main .block-title'))->toBe([
        'How can we help?',
        'Start with your role',
        'Getting-started Path',
        'Product Areas',
        'For your clients',
        'What’s new',
        'Look something up',
        'New to First Mile Manager?',
    ])
        ->and($page->has('.home-search form.search[action="/search"][method="get"] input[name="q"]'))->toBeTrue()
        ->and($page->text('title'))->toBe('How can we help? | First Mile Manager Help centre');
});

it('offers a tile for each Role Path', function () {
    aSmallHelpCentre()->publish();

    expect(ReaderPage::of($this->get('/'))->links('.role-tile'))->toBe([
        ['I’m an Admin Set up your business and your team, and give people their roles.', '/roles/admin'],
        ['I’m a Finance Manager Look after your business’s money: collection charges and, later, invoices.', '/roles/finance-manager'],
        ['I’m an Account Manager Look after clients: their details, contacts, schedules, queries and failures.', '/roles/account-manager'],
        ['I’m a Transport Manager Keep vehicles, organise routes, watch progress and invite Drivers.', '/roles/transport-manager'],
        ['I’m a Driver Drive collection rounds and record proof of collection and failures.', '/roles/driver'],
        ['I’m a Warehouse Manager Check in collected goods, settle check-in exceptions and invite Warehouse Operatives.', '/roles/warehouse-manager'],
        ['I’m a Warehouse Operative Check in collected goods.', '/roles/warehouse-operative'],
    ]);
});

it('shows the Getting-started Path as a numbered rail of its Published steps, step one marked as the place to start', function () {
    aSmallHelpCentre()->publish();

    $page = ReaderPage::of($this->get('/'));

    expect($page->links('.path-rail a'))->toBe([
        ['Welcome: what you’ll set up', '/articles/get-started'],
        ['Set up your team', '/articles/get-started-team'],
        ['Build your warehouse', '/articles/get-started-warehouse'],
    ])
        ->and($page->texts('.path-rail li'))->toBe(['Step 1 Start here Welcome: what you’ll set up', 'Step 2 Set up your team', 'Step 3 Build your warehouse'])
        ->and($page->links('.home-path a.see-all'))->toBe([['See the whole path', '/getting-started']]);
});

it('shows each Product Area with a Published Article as a sign with its count, in the app\'s menu order', function () {
    aSmallHelpCentre()->publish();

    // Products has only a Draft, so it isn't shown; Inventory and Clients aren't in the menus list, so they come last.
    expect(ReaderPage::of($this->get('/'))->links('.area-sign'))->toBe([
        ['Team 2 articles', '/areas/team'],
        ['Warehouses 2 articles', '/areas/warehouses'],
        ['Inbound 2 articles', '/areas/inbound'],
        ['Clients 1 article', '/areas/clients'],
        ['Inventory 1 article', '/areas/inventory'],
    ]);
});

it('lists the Client-tier Articles and the Client Glossary under For your clients', function () {
    aSmallHelpCentre()->publish();

    expect(ReaderPage::of($this->get('/'))->links('.home-clients a'))->toBe([
        ['Signing in for the first time', '/articles/signing-in'],
        ['Seeing your stock', '/articles/seeing-your-stock'],
        ['Client Glossary The words your clients see', '/client-glossary'],
        ['See everything for your clients', '/for-your-clients'],
    ]);
});

it('shows the latest Release Notes under What\'s new, newest first, marking those that affect clients', function () {
    aSmallHelpCentre()->publish();

    expect(ReaderPage::of($this->get('/'))->links('.home-whats-new a'))->toBe([
        ['Warehouse Managers set up carriers. 2 December 2026', '/whats-new/2026-12-02'],
        ['Zones have a walk order. 21 November 2026', '/whats-new/2026-11-21'],
        ['Delivery numbers now carry their warehouse. 14 November 2026 Affects your clients', '/whats-new/2026-11-14'],
        ['See everything new', '/whats-new'],
    ]);
});

it('links to Troubleshooting and the Glossary', function () {
    aSmallHelpCentre()->publish();

    expect(ReaderPage::of($this->get('/'))->links('.home-lookup a'))->toBe([
        ['Troubleshooting Fixes for First Mile Manager’s messages and problems', '/troubleshooting'],
        ['Glossary What First Mile Manager’s words mean', '/glossary'],
    ]);
});

it('points someone new to the Getting-started Path and the marketing site', function () {
    aSmallHelpCentre()->facts(['url' => 'https://firstmilemanager.co.uk'])->publish();

    expect(ReaderPage::of($this->get('/'))->links('.home-new a'))->toBe([
        ['Getting-started Path', '/getting-started'],
        ['what First Mile Manager does', 'https://firstmilemanager.co.uk'],
        ['early access', 'https://firstmilemanager.co.uk/early-access'],
    ]);
});

it('never states a price, a plan or what is coming: those are only links to the marketing site', function () {
    aSmallHelpCentre()->publish();

    $page = ReaderPage::of($this->get('/'));
    $words = $page->text('body');

    expect($words)->not->toMatch('/£|\bper (month|year|user|client)\b|\/month|\bVAT\b|roadmap|in development|coming soon|\bStarter\b|\bGrowth\b|\bScale\b/i');
    foreach ($page->links('a') as [$text, $address]) {
        if (preg_match('/price|plan\b|plans|coming/i', $text) === 1) {
            expect($address)->toStartWith('https://firstmilemanager.co.uk/');
        }
    }
});

it('leaves out the path, the client help and What\'s new when there is none yet', function () {
    content()->article('book-in-a-shipment')->publish();

    $page = ReaderPage::of($this->get('/'));

    expect($page->has('.home-path'))->toBeFalse()
        ->and($page->has('.home-clients'))->toBeFalse()
        ->and($page->has('.home-whats-new'))->toBeFalse()
        ->and($page->links('.area-sign'))->toBe([['Inbound 1 article', '/areas/inbound']])
        ->and($page->has('.role-tile'))->toBeTrue();
});
