<?php

use Tests\Support\FixtureContent;
use Tests\Support\ReaderPage;

/*
| The listings (spec #30, user stories 2 to 5, 7, 15, 16, 48 and 83; plan, "Listings"): Role
| Paths, the Getting-started Path, Product Areas, For your clients and Troubleshooting gather
| Articles from every Product Area without copying them. Only Published Articles appear.
*/

/** Articles across three areas, with a Draft and a Retired Article that must never be listed. */
function aListedHelpCentre(): FixtureContent
{
    return content()
        ->productAreas(['Team' => 'team.cluster.title', 'Stock Containers' => 'stock-containers.cluster.title', 'Inbound' => 'inbound.cluster.title', 'Inventory' => 'inventory.cluster.title'])
        ->article('get-started-team', ['title' => 'Set up your team', 'type' => 'Getting-started', 'path_position' => 2, 'product_area' => 'Team'])
        ->article('get-started', ['title' => 'Welcome: what you’ll set up', 'type' => 'Getting-started', 'path_position' => 1, 'product_area' => 'Team'])
        ->article('invite-your-team', ['title' => 'Invite your team', 'product_area' => 'Team'])
        ->article('team-roles', ['title' => 'What each Team Role can do', 'type' => 'Concept', 'product_area' => 'Team', 'team_roles' => ['Admin', 'Warehouse Operative']])
        ->article('purchase-orders-and-shipments', ['title' => 'Purchase orders and inbound shipments', 'type' => 'Concept', 'team_roles' => ['Admin', 'Warehouse Operative']])
        ->article('receive-at-the-dock', ['title' => 'Receive a shipment at the dock', 'team_roles' => ['Admin', 'Warehouse Operative']])
        ->article('book-in-a-shipment', ['title' => 'Book in a shipment'])
        ->article('cant-receive-for-this-client', ['title' => 'The warehouse can’t receive for a client', 'type' => 'Troubleshooting', 'team_roles' => ['Admin', 'Warehouse Operative']])
        ->article('stock-wont-fit', ['title' => 'Stock won’t go into a location', 'type' => 'Troubleshooting', 'product_area' => 'Stock Containers', 'team_roles' => ['Admin', 'Warehouse Operative']])
        ->article('move-a-container', ['title' => 'Move a container', 'product_area' => 'Stock Containers', 'team_roles' => ['Admin', 'Warehouse Operative']])
        ->article('location-codes', ['title' => 'Location codes', 'type' => 'Reference', 'product_area' => 'Stock Containers'])
        ->article('a-draft-task', ['title' => 'A draft task', 'status' => 'Draft', 'team_roles' => ['Admin', 'Warehouse Operative']])
        ->article('a-draft-fix', ['title' => 'A draft fix', 'type' => 'Troubleshooting', 'status' => 'Draft'])
        ->article('a-draft-step', ['title' => 'A draft step', 'type' => 'Getting-started', 'path_position' => 3, 'status' => 'Draft'])
        ->article('an-old-task', ['title' => 'An old task', 'status' => 'Retired', 'what_changed' => 'First Mile Manager no longer does this.', 'team_roles' => ['Admin', 'Warehouse Operative']])
        ->article('your-deliveries', ['title' => 'Following your deliveries', 'audience_tier' => 'Client'])
        ->article('seeing-your-stock', ['title' => 'Seeing your stock', 'audience_tier' => 'Client', 'product_area' => 'Inventory'])
        ->article('your-account-status', ['title' => 'Your account status', 'audience_tier' => 'Client', 'product_area' => 'Inventory', 'type' => 'Concept'])
        ->article('an-old-client-page', ['title' => 'An old client page', 'audience_tier' => 'Client', 'status' => 'Retired', 'what_changed' => 'Gone.'])
        ->clientGlossaryEntry('on-hold')
        ->clientGlossaryEntry('account-status');
}

/**
 * A listing's groups as a reader scans them: each group's heading with its links' words.
 *
 * @return array<string, list<string>>
 */
function groupsOn(ReaderPage $page): array
{
    $headings = $page->texts('.listing-group h2');
    $groups = [];
    foreach ($headings as $index => $heading) {
        $groups[$heading] = array_map(fn (array $link): string => $link[0], $page->links('.listing-group:nth-of-type('.($index + 1).') li a'));
    }

    return $groups;
}

it('lists an Admin\'s Articles by Product Area, Getting-started and How-to first', function () {
    aListedHelpCentre()->publish();

    $page = ReaderPage::of($this->get('/roles/admin'));

    expect($page->text('h1'))->toBe('Admin')
        ->and($page->links('.crumbs a'))->toBe([['Help centre', '/']])
        ->and(groupsOn($page))->toBe([
            'Team' => ['Welcome: what you’ll set up Getting-started', 'Set up your team Getting-started', 'Invite your team How-to', 'What each Team Role can do Concept'],
            'Stock Containers' => ['Move a container How-to', 'Location codes Reference', 'Stock won’t go into a location Troubleshooting'],
            'Inbound' => ['Book in a shipment How-to', 'Receive a shipment at the dock How-to', 'Purchase orders and inbound shipments Concept', 'The warehouse can’t receive for a client Troubleshooting'],
        ])
        ->and($page->links('.listing-group li a')[0][1])->toBe('/articles/get-started')
        ->and($page->links('.listing-group h2 a'))->toBe([['Team', '/areas/team'], ['Stock Containers', '/areas/stock-containers'], ['Inbound', '/areas/inbound']]);
});

it('lists only the tasks tagged for a Warehouse Operative on theirs', function () {
    aListedHelpCentre()->publish();

    $page = ReaderPage::of($this->get('/roles/warehouse-operative'));

    expect($page->text('h1'))->toBe('Warehouse Operative')
        ->and(groupsOn($page))->toBe([
            'Team' => ['What each Team Role can do Concept'],
            'Stock Containers' => ['Move a container How-to', 'Stock won’t go into a location Troubleshooting'],
            'Inbound' => ['Receive a shipment at the dock How-to', 'Purchase orders and inbound shipments Concept', 'The warehouse can’t receive for a client Troubleshooting'],
        ]);
});

it('shows a Role Path with nothing tagged for it yet as a page saying so', function () {
    content()->article('book-in-a-shipment')->publish();

    $page = ReaderPage::of($this->get('/roles/warehouse-operative'));

    expect($page->has('.listing-group'))->toBeFalse()
        ->and($page->text('.listing-empty'))->toBe('There are no articles for this role yet. Search, or start from the help centre home.');
});

it('lists the Getting-started Path\'s Published steps in order', function () {
    aListedHelpCentre()->publish();

    $page = ReaderPage::of($this->get('/getting-started'));

    expect($page->text('h1'))->toBe('Getting-started Path')
        ->and($page->links('.path-list a'))->toBe([
            ['Welcome: what you’ll set up', '/articles/get-started'],
            ['Set up your team', '/articles/get-started-team'],
        ])
        ->and($page->texts('.path-list .step-n'))->toBe(['Step 1', 'Step 2'])
        ->and($page->text('.path-list li.start'))->toContain('Start here');
});

it('lists every Article in a Product Area by Article Type, client help marked', function () {
    aListedHelpCentre()->publish();

    $page = ReaderPage::of($this->get('/areas/inbound'));

    expect($page->text('h1'))->toBe('Inbound')
        ->and($page->text('title'))->toBe('Inbound | First Mile Manager Help centre')
        ->and($page->links('.crumbs a'))->toBe([['Help centre', '/']])
        ->and(groupsOn($page))->toBe([
            'How-to' => ['Book in a shipment', 'Following your deliveries For your clients', 'Receive a shipment at the dock'],
            'Concept' => ['Purchase orders and inbound shipments'],
            'Troubleshooting' => ['The warehouse can’t receive for a client'],
        ]);
});

it('lists the Client-tier Articles and the Client Glossary under For your clients', function () {
    aListedHelpCentre()->publish();

    $page = ReaderPage::of($this->get('/for-your-clients'));

    expect($page->text('h1'))->toBe('For your clients')
        ->and($page->links('.client-articles a'))->toBe([
            ['Following your deliveries Inbound', '/articles/your-deliveries'],
            ['Seeing your stock Inventory', '/articles/seeing-your-stock'],
            ['Your account status Inventory', '/articles/your-account-status'],
        ])
        ->and($page->links('.client-glossary a'))->toBe([
            ['Client Glossary', '/client-glossary'],
            ['Account Status', '/client-glossary/account-status'],
            ['On Hold', '/client-glossary/on-hold'],
        ]);
});

it('lists Troubleshooting Articles by Product Area', function () {
    aListedHelpCentre()->publish();

    $page = ReaderPage::of($this->get('/troubleshooting'));

    expect($page->text('h1'))->toBe('Troubleshooting')
        ->and(groupsOn($page))->toBe([
            'Stock Containers' => ['Stock won’t go into a location'],
            'Inbound' => ['The warehouse can’t receive for a client'],
        ]);
});

it('never lists a Draft or Retired Article', function (string $address) {
    aListedHelpCentre()->publish();

    $words = ReaderPage::of($this->get($address))->text('main');

    expect($words)->not->toContain('A draft')
        ->and($words)->not->toContain('An old');
})->with(['/', '/roles/admin', '/roles/warehouse-operative', '/getting-started', '/areas/inbound', '/for-your-clients', '/troubleshooting']);

it('has no page for a Product Area without a Published Article, or a Role Path that doesn\'t exist', function (string $address) {
    content()
        ->article('book-in-a-shipment')
        ->article('add-a-product', ['product_area' => 'Products', 'status' => 'Draft'])
        ->publish();

    $this->get($address)->assertNotFound();
})->with(['/areas/products', '/areas/billing', '/roles/office-manager']);

it('sends a renamed Product Area\'s old address to its new one, for good', function () {
    content()
        ->productAreas(['Stock Containers' => 'stock-containers.cluster.title'])
        ->file('product-areas.yaml', "- name: Stock Containers\n  menu: stock-containers.cluster.title\n  former_names: [Containers, Stock Units]\n")
        ->article('move-a-container', ['product_area' => 'Stock Containers'])
        ->publish();

    $this->get('/areas/containers')->assertStatus(301)->assertRedirect('/areas/stock-containers');
    $this->get('/areas/stock-units?from=email')->assertStatus(301)->assertRedirect('/areas/stock-containers?from=email');
    $this->get('/areas/stock-containers')->assertOk();
});

it('refuses a former name that an area still uses', function () {
    ['exitCode' => $exitCode, 'output' => $output] = content()
        ->file('product-areas.yaml', "- name: Stock Containers\n  menu: stock-containers.cluster.title\n  former_names: [Inbound]\n- name: Inbound\n  menu: inbound.cluster.title\n")
        ->article('book-in-a-shipment')
        ->rebuild();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('product-areas.yaml: former_names: "/areas/inbound" is in use by');
});

it('refuses a Product Area list it can\'t read', function (string $yaml, string $problem) {
    ['exitCode' => $exitCode, 'output' => $output] = content()->file('product-areas.yaml', $yaml)->rebuild();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain($problem);
})->with([
    'no name' => ["- menu: inbound.cluster.title\n", 'product-areas.yaml: name: each Product Area needs its name'],
    'former names not a list' => ["- name: Inbound\n  former_names: Goods In\n", 'product-areas.yaml: former_names: "Inbound": must be a list'],
    'a typo' => ["- name: Inbound\n  formr_names: [Goods In]\n", 'product-areas.yaml: formr_names: "Inbound": is not a Product Area field'],
]);
