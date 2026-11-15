<?php

use Illuminate\Console\Command;
use Tests\Support\FixtureContent;
use Tests\Support\FixtureMainProject;

/*
 * Launch Check 4, Glossary (spec #30, seam 2): the published Glossary keeps up with the main
 * project's GLOSSARY.md. Only an entry naming a term GLOSSARY.md no longer has fails; the rest
 * is reported to the Content Owner, until the launch switch makes a missing Term Status fail.
 *
 * Fingerprints are the first 12 hex digits of the SHA-256 of a definition, worked out by hand
 * (printf '%s' 'A place stock is kept.' | sha256sum).
 */

const LOCATION = 'A place stock is kept.';
const LOCATION_FINGERPRINT = '6974c41e29f4';
const STOCK_CONTAINER_FINGERPRINT = 'eb5531fea48a';
const PICK_LIST_FINGERPRINT = '20883cf3dc04';
const ON_HOLD_FINGERPRINT = '5415b091375f';

/** @param  array<string, string|array{0: string, 1?: list<string>}>  $more */
function glossaryProject(array $more = [], array $without = []): FixtureMainProject
{
    $terms = [
        'Location' => [LOCATION, ['Bin', 'slot']],
        'Stock Container' => ['A container that holds stock.', ['tote']],
        'Pick List' => ['A list of orders to pick.', ['wave']],
        'On Hold' => 'A pause on a client account.',
        ...$more,
    ];

    return mainProject()->glossary(array_diff_key($terms, array_flip($without)));
}

/** @param  array<string, array<string, mixed>|null>  $changes  term => fields to change, or null to drop the term */
function glossaryContent(array $changes = []): FixtureContent
{
    $statuses = [
        'Location' => ['status' => 'Published', 'product_area' => 'Warehouses', 'fingerprint' => LOCATION_FINGERPRINT],
        'Stock Container' => ['status' => 'Published', 'product_area' => 'Stock Containers', 'fingerprint' => STOCK_CONTAINER_FINGERPRINT],
        'Pick List' => ['status' => 'Not built yet', 'product_area' => 'Outbound', 'fingerprint' => PICK_LIST_FINGERPRINT],
        'On Hold' => ['status' => 'Published', 'product_area' => 'Clients', 'fingerprint' => ON_HOLD_FINGERPRINT],
    ];
    foreach ($changes as $term => $fields) {
        if ($fields === null) {
            unset($statuses[$term]);
        } else {
            $statuses[$term] = [...$statuses[$term] ?? [], ...$fields];
        }
    }

    return content()
        ->productAreas(['Warehouses' => 'warehouses.cluster.title', 'Stock Containers' => 'stock_containers.cluster.title', 'Clients' => 'clients.cluster.title'])
        ->termStatus($statuses)
        ->glossaryEntry('location')
        ->glossaryEntry('stock-container')
        ->glossaryEntry('on-hold')
        ->clientGlossaryEntry('on-hold');
}

it('passes a Glossary that keeps up with GLOSSARY.md, with nothing to report', function () {
    $run = launchChecks(glossaryContent())->against(glossaryProject())->run();

    expect($run->exitCode)->toBe(Command::SUCCESS)
        ->and($run->failures())->toBe([])
        ->and($run->reports())->toBe([]);
});

it('fails a published entry naming a term GLOSSARY.md no longer has', function () {
    $run = launchChecks(glossaryContent())->against(glossaryProject(without: ['Stock Container']))->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe([
            'glossary/stock-container.md:2 [glossary.term] "Stock Container" is not a term in GLOSSARY.md: an entry names a term exactly as GLOSSARY.md does, so rename the entry with the term\'s new name (keeping the old one in former_names) or remove it',
        ])
        ->and($run->reports())->toContain('term-status.yaml:5 [glossary.term-status] "Stock Container" is not a term in GLOSSARY.md: take it off the list, or rename it as GLOSSARY.md now names it');
});

it('fails an entry whose term is written other than exactly as GLOSSARY.md writes it', function () {
    $run = launchChecks(glossaryContent()->glossaryEntry('stock-container', ['term' => 'Stock container']))->against(glossaryProject())->run();

    expect($run->failures())->toBe([
        'glossary/stock-container.md:2 [glossary.term] "Stock container" is not a term in GLOSSARY.md, which writes it "Stock Container"',
    ]);
});

it('fails a Client Glossary entry naming a term GLOSSARY.md no longer has', function () {
    $run = launchChecks(glossaryContent())->against(glossaryProject(without: ['On Hold']))->run();

    expect($run->failures())->toHaveCount(2)
        ->and($run->failures()[1])->toStartWith('client-glossary/on-hold.md:2 [glossary.term] "On Hold" is not a term in GLOSSARY.md');
});

it('reports, without failing, what the Content Owner should look at', function (Closure $breaks, string $report) {
    [$content, $project] = $breaks();
    $run = launchChecks($content)->against($project)->run();

    expect($run->exitCode)->toBe(Command::SUCCESS)
        ->and($run->failures())->toBe([])
        ->and($run->reports())->toBe([$report]);
})->with([
    'a term with no Term Status' => [
        fn () => [glossaryContent(), glossaryProject(['Dock Override' => 'Letting a delivery in outside its booking.'])],
        'term-status.yaml [glossary.term-status] "Dock Override" has no Term Status: add it with its status (Published, Internal or Not built yet), its home product_area and fingerprint: fddf3a9a8f8c (this fails at launch)',
    ],
    'a Not built yet term the main project\'s screens now show' => [
        fn () => [glossaryContent(), glossaryProject()->menu('outbound.pick_lists.title', 'Pick lists')],
        'term-status.yaml:9 [glossary.not-built-yet] "Pick List" is Not built yet, but the main project\'s screens now show it (lang/en/outbound.php): if its feature has shipped, write its Glossary Entry and mark it Published',
    ],
    'an Ahead term' => [
        fn () => [glossaryContent(['Location' => ['ahead' => true]]), glossaryProject()],
        'term-status.yaml:1 [glossary.ahead] "Location" is marked Ahead: its entries say less than GLOSSARY.md, so once the rest of its definition ships, update them and take the mark off',
    ],
    'a definition changed since it was last checked' => [
        fn () => [glossaryContent(), glossaryProject(['Location' => 'A place stock is kept, with a code on its label.'])],
        'term-status.yaml:1 [glossary.definition] GLOSSARY.md\'s definition of "Location" has changed since it was last checked: check its entries against it, then set fingerprint: 056aa35535b5',
    ],
    'a Published term with no entry' => [
        fn () => [glossaryContent(['Pick List' => ['status' => 'Published', 'product_area' => 'Warehouses']]), glossaryProject()],
        'term-status.yaml:9 [glossary.entries] "Pick List" is Published but has no Glossary Entry (glossary/pick-list.md or client-glossary/pick-list.md)',
    ],
    'an entry for a term that is not Published' => [
        fn () => [glossaryContent(['Stock Container' => ['status' => 'Internal']]), glossaryProject()],
        'glossary/stock-container.md:2 [glossary.entries] "Stock Container" has a Glossary Entry, but its Term Status is Internal: mark it Published, or remove the entry',
    ],
]);

it('fails a term with no Term Status once the launch switch is on', function () {
    $run = launchChecks(glossaryContent(['On Hold' => null]))->against(glossaryProject())->run(['--at-launch' => true]);

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures('glossary'))->toBe([
            'term-status.yaml [glossary.term-status] "On Hold" has no Term Status: add it with its status (Published, Internal or Not built yet), its home product_area and fingerprint: '.ON_HOLD_FINGERPRINT,
        ]);
});

it('turns the launch switch on with going public', function () {
    config(['help.going_public' => true]);

    $run = launchChecks(glossaryContent(['On Hold' => null]))->against(glossaryProject())->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures('glossary'))->toHaveCount(1);
});

it('fails a Term Status list it can\'t use, naming the line', function (array $change, string $failure) {
    $run = launchChecks(glossaryContent($change))->against(glossaryProject())->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe([$failure]);
})->with([
    'an unknown status' => [['Location' => ['status' => 'Live']], 'term-status.yaml:1 [glossary.term-status-format] "Location": status "Live" must be one of: Published, Internal, Not built yet'],
    'no home Product Area' => [['Location' => ['product_area' => '']], 'term-status.yaml:1 [glossary.term-status-format] "Location": product_area is missing: the Product Area whose Articles first use the term'],
    'a Published term\'s area not on the Product Area list' => [['Location' => ['product_area' => 'Floor']], 'term-status.yaml:1 [glossary.term-status-format] "Location": product_area "Floor" is not on the Product Area list (product-areas.yaml)'],
    'Ahead on a term that is not Published' => [['Pick List' => ['ahead' => true]], 'term-status.yaml:9 [glossary.term-status-format] "Pick List": only a Published term can be marked Ahead'],
    'no fingerprint' => [['On Hold' => ['fingerprint' => null]], 'term-status.yaml:13 [glossary.term-status-format] "On Hold": fingerprint is missing: set fingerprint: '.ON_HOLD_FINGERPRINT],
    'an unknown field' => [['On Hold' => ['staff_only' => true]], 'term-status.yaml:13 [glossary.term-status-format] "On Hold": staff_only is not a Term Status field (known fields: status, product_area, ahead, fingerprint, stockist_fingerprint)'],
]);

it('fails a Term Status list that isn\'t valid YAML', function () {
    $run = launchChecks(glossaryContent()->file('term-status.yaml', "Location:\n  status: Published\n Stock Container: [\n"))->against(glossaryProject())->run();

    expect($run->failures())->toHaveCount(1)
        ->and($run->failures()[0])->toStartWith('term-status.yaml:3 [glossary.term-status-format] is not valid YAML');
});

it('reports every term as having no Term Status while there is no list', function () {
    $content = glossaryContent()->file('term-status.yaml', '');
    unlink($content->path().'/term-status.yaml');

    $run = launchChecks($content)->against(glossaryProject())->run();

    expect($run->exitCode)->toBe(Command::SUCCESS)
        ->and(array_filter($run->reports(), fn (string $line): bool => str_contains($line, '[glossary.term-status]')))->toHaveCount(4);
});

it('fails when the main project has no GLOSSARY.md', function () {
    $project = glossaryProject();
    unlink($project->path().'/GLOSSARY.md');

    $run = launchChecks(glossaryContent())->against($project)->run();

    expect($run->failures())->toBe([
        'GLOSSARY.md [glossary.source] the main project has no GLOSSARY.md, so no term can be checked: is --main-project a checkout of gpl-ajackson/firstmilemanager?',
    ]);
});

/*
 * Shared terms (fmm#56): a term both First Mile Manager's GLOSSARY.md and Stockist's define must
 * mean in help what Stockist means by it, since a Joint Operator's people meet it in both apps.
 */

/** Stockist's checkout, defining On Hold (shared) and a term of its own. */
function stockistGlossary(string $onHold = 'A pause on a client account.'): FixtureMainProject
{
    return mainProject()->glossary([
        'On Hold' => [$onHold, ['suspended']],
        'Putaway' => 'Moving received stock to a Location.',
    ]);
}

it('passes a shared term whose Term Status records Stockist\'s definition as checked', function () {
    $run = launchChecks(glossaryContent(['On Hold' => ['stockist_fingerprint' => ON_HOLD_FINGERPRINT]]))
        ->against(glossaryProject())->stockist(stockistGlossary())->run();

    expect($run->failures())->toBe([])
        ->and($run->reports())->toBe([])
        ->and($run->output)->not->toContain('Not run: Glossary');
});

it('fails a shared term whose Term Status has no stockist_fingerprint', function () {
    $run = launchChecks(glossaryContent())->against(glossaryProject())->stockist(stockistGlossary())->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe([
            'term-status.yaml:13 [glossary.shared-term] "On Hold" is a term in Stockist\'s GLOSSARY.md too: check that its Glossary Entry and the Articles that use it mean what Stockist means by it ("A pause on a client account."), then set stockist_fingerprint: \''.ON_HOLD_FINGERPRINT.'\'',
        ]);
});

it('reports a shared term whose Stockist definition has changed since it was checked', function () {
    $run = launchChecks(glossaryContent(['On Hold' => ['stockist_fingerprint' => ON_HOLD_FINGERPRINT]]))
        ->against(glossaryProject())->stockist(stockistGlossary('A pause on a whole client account.'))->run();

    expect($run->failures())->toBe([])
        ->and($run->reports())->toBe([
            'term-status.yaml:13 [glossary.shared-meaning] Stockist\'s GLOSSARY.md has changed its definition of "On Hold" since it was last checked: check that its Glossary Entry and the Articles that use it still mean what Stockist means by it, then set stockist_fingerprint: \'d0bdd9c2c532\'',
        ]);
});

it('reports a stockist_fingerprint on a term Stockist doesn\'t define', function () {
    $run = launchChecks(glossaryContent([
        'On Hold' => ['stockist_fingerprint' => ON_HOLD_FINGERPRINT],
        'Location' => ['stockist_fingerprint' => LOCATION_FINGERPRINT],
    ]))->against(glossaryProject())->stockist(stockistGlossary())->run();

    expect($run->failures())->toBe([])
        ->and($run->reports())->toBe([
            'term-status.yaml:1 [glossary.shared-term] "Location" is not a term in Stockist\'s GLOSSARY.md any more: take its stockist_fingerprint off',
        ]);
});

it('fails an entry naming a term only Stockist defines', function () {
    $run = launchChecks(glossaryContent(['On Hold' => ['stockist_fingerprint' => ON_HOLD_FINGERPRINT]])->glossaryEntry('putaway', ['term' => 'Putaway']))
        ->against(glossaryProject())->stockist(stockistGlossary())->run();

    expect($run->failures())->toBe([
        'glossary/putaway.md:2 [glossary.term] "Putaway" is a term in Stockist\'s GLOSSARY.md, not First Mile Manager\'s: this help centre\'s Glossary explains First Mile Manager\'s terms, so explain it in a Joint-only Article instead',
    ]);
});

it('doesn\'t check shared terms without a checkout of Stockist, and says so', function () {
    $run = launchChecks(glossaryContent())->against(glossaryProject())->run();

    expect($run->failures())->toBe([])
        ->and($run->output)->toContain('Not run: Glossary, the terms First Mile Manager shares with Stockist: give --stockist=<a checkout of gpl-ajackson/stockist>');
});

it('fails a Stockist checkout with no GLOSSARY.md', function () {
    $stockist = stockistGlossary();
    unlink($stockist->path().'/GLOSSARY.md');

    $run = launchChecks(glossaryContent())->against(glossaryProject())->stockist($stockist)->run();

    expect($run->failures())->toContain(
        'GLOSSARY.md [glossary.stockist-source] Stockist\'s checkout at '.$stockist->path().' has no GLOSSARY.md, so the shared terms can\'t be checked: is --stockist a checkout of gpl-ajackson/stockist?',
    );
});
