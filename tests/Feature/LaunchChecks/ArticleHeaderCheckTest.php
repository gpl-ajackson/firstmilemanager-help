<?php

use Illuminate\Console\Command;
use Tests\Support\FixtureContent;
use Tests\Support\FixtureMainProject;

/*
 * Launch Check 1, Article header (spec #30, seam 2): run over a fixture content folder and a
 * folder shaped like the main project. Fixture Articles' header data is written in this order,
 * so each field sits on a known line: 1 "---", 2 title, 3 summary, 4 type, 5 product_area,
 * 6 audience_tier, 7 owner, 8 status, 9 checked_on, 10 next_review_on, 11 team_roles (a list),
 * and so on; the body starts on line 20.
 */

function cleanContent(): FixtureContent
{
    return content()
        ->productAreas(['Inbound' => 'inbound.cluster.title', 'Warehouses' => 'warehouses.cluster.title'])
        ->articleOwners(['gpl-ajackson', 'trentside-writer'])
        ->article('receive-a-delivery', [], "## In short\n\nScan the delivery in at the dock.\n\n## Steps\n\n1. Scan the label.\n2. Select **Receive**.\n")
        ->article('how-locations-work', ['type' => 'Concept', 'product_area' => 'Warehouses', 'owner' => 'trentside-writer'], "## In short\n\nEvery shelf has a code.\n");
}

it('passes the clean fixture set, run locally with a path to a main-project checkout', function () {
    $run = launchChecks(cleanContent())->against(mainProject())->run();

    expect($run->exitCode)->toBe(Command::SUCCESS)
        ->and($run->failures())->toBe([])
        ->and($run->output)->toContain('0 failures');
});

it('fails header data the rebuild would refuse, naming file, line and rule', function (array $header, string $line) {
    $run = launchChecks(cleanContent()->article('move-a-container', $header))->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toContain($line);
})->with([
    'a title over 60 characters' => [['title' => str_repeat('a', 61)], 'articles/move-a-container.md:2 [header.title] must be 60 characters at most (it has 61)'],
    'an unknown Article Type' => [['type' => 'Tutorial'], 'articles/move-a-container.md:4 [header.type] "Tutorial" must be one of: Getting-started, How-to, Concept, Reference, Troubleshooting, FAQ'],
    'a Checked On not in the calendar' => [['checked_on' => '2026-02-30'], 'articles/move-a-container.md:9 [header.checked_on] "2026-02-30" must be a date written YYYY-MM-DD'],
    'a missing summary' => [['summary' => null], 'articles/move-a-container.md:1 [header.summary] is missing'],
]);

it('fails what the rebuild refuses across files, such as a replacement that doesn\'t exist', function () {
    $run = launchChecks(cleanContent()->article('move-a-container', ['status' => 'Retired', 'replacement' => 'no-such-article']))->run();

    expect($run->failures())->toBe([
        'articles/move-a-container.md:18 [header.replacement] there is no Article named "no-such-article"',
    ]);
});

it('fails a summary of more than one sentence', function () {
    $run = launchChecks(cleanContent()->article('move-a-container', ['summary' => 'Move a container. Then label it.']))->run();

    expect($run->failures())->toBe([
        'articles/move-a-container.md:3 [header.summary] must be one sentence: what the reader will be able to do or know',
    ]);
});

it('fails an owner who is not on the Article Owners list', function () {
    $run = launchChecks(cleanContent()->article('move-a-container', ['owner' => 'someone-else']))->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe([
            'articles/move-a-container.md:7 [header.owner] "someone-else" is not on the Article Owners list (article-owners.yaml); an Article Owner is a named Stockist person',
        ]);
});

it('fails an agent on the Article Owners list', function (string $login) {
    $run = launchChecks(cleanContent()->articleOwners(['gpl-ajackson', 'trentside-writer', $login]))->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe([
            "article-owners.yaml:3 [owners.person] \"{$login}\" looks like an agent or bot; an Article Owner is a person, never an agent",
        ]);
})->with(['claude', 'github-actions[bot]', 'copilot', 'help-bot']);

it('fails a Product Area that is not on the Product Area list', function () {
    $run = launchChecks(cleanContent()->article('pack-an-order', ['product_area' => 'Outbound']))->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe([
            'articles/pack-an-order.md:5 [header.product_area] "Outbound" is not on the Product Area list (product-areas.yaml): Inbound, Warehouses',
        ]);
});

it('fails a listed Product Area missing from the main project\'s menus', function () {
    $content = cleanContent()->productAreas([
        'Inbound' => 'inbound.cluster.title',
        'Warehouses' => 'warehouses.cluster.title',
        'Outbound' => 'outbound.cluster.title',
    ]);

    $run = launchChecks($content)->against(mainProject())->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe([
            'product-areas.yaml:5 [product-areas.menu] "Outbound": the main project has no menu label outbound.cluster.title (lang/en/outbound.php)',
        ]);
});

it('fails a listed Product Area whose menu the main project has renamed', function () {
    $run = launchChecks(cleanContent())
        ->against(mainProject()->menu('inbound.cluster.title', 'Goods in'))
        ->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe([
            'product-areas.yaml:1 [product-areas.menu] "Inbound" must be named exactly as the app\'s menu, which is now "Goods in" (inbound.cluster.title)',
        ]);
});

it('fails Team Roles that don\'t exist in the main project', function () {
    $content = cleanContent()->article('count-a-location', ['team_roles' => ['Warehouse Operative', 'Floor Supervisor']]);

    $run = launchChecks($content)->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe([
            'articles/count-a-location.md:13 [header.team_roles] "Floor Supervisor" is not a Team Role in First Mile Manager; Team Roles that exist: Admin, Warehouse Operative, Client User, Client Account Administrator',
        ]);
});

it('reads Team Roles from the main project at the ref under test', function () {
    $content = cleanContent()->article('count-a-location', ['team_roles' => ['Floor Supervisor']]);
    $withOb1 = mainProject()->teamRoles([...FixtureMainProject::TEAM_ROLES, 'FloorSupervisor' => 'Floor Supervisor']);

    expect(launchChecks($content)->against($withOb1)->run()->failures())->toBe([]);
});

it('fails a Client-tier Article that names Team Roles', function () {
    $content = cleanContent()->article('see-your-stock', ['audience_tier' => 'Client', 'team_roles' => ['Client User']]);

    $run = launchChecks($content)->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe([
            'articles/see-your-stock.md:11 [header.team_roles] a Client-tier Article names no Team Roles in its details',
        ]);
});

it('checks every Article, drafts included, and names each problem once', function () {
    $run = launchChecks(cleanContent()
        ->article('a-draft', ['status' => 'Draft', 'owner' => 'nobody-listed'])
        ->article('another-draft', ['status' => 'Draft', 'product_area' => 'Billing']))
        ->run();

    expect($run->failures())->toBe([
        'articles/a-draft.md:7 [header.owner] "nobody-listed" is not on the Article Owners list (article-owners.yaml); an Article Owner is a named Stockist person',
        'articles/another-draft.md:5 [header.product_area] "Billing" is not on the Product Area list (product-areas.yaml): Inbound, Warehouses',
    ]);
});
