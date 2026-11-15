<?php

use Illuminate\Console\Command;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FixtureContent;
use Tests\Support\FixtureMainProject;

/*
 * Launch Check 2, Source References (spec #30, seam 2; user story 119): both halves of every
 * Source Reference agree. An Article cites plans, ADRs and issues in the main project; each
 * cited plan or spec lists the Article back in its Help content section, and everything a Help
 * content section lists exists. The main project is a folder shaped like its repository; its
 * issues (specs) are served by a faked GitHub. Fixture Articles' source_references sit on
 * lines 13 onwards (one per line), and a fixture plan's Help content starts on line 7.
 */

beforeEach(fn () => Http::preventStrayRequests());

const RECEIVING_PLAN = '.junie/plans/Inbound/2026-10-01-120000-receiving.md';

function sourcedContent(): FixtureContent
{
    return content()
        ->productAreas(['Inbound' => 'inbound.cluster.title'])
        ->articleOwners(['gpl-ajackson'])
        ->termStatus(['ASN' => ['status' => 'Published', 'product_area' => 'Inbound', 'fingerprint' => 'b67e9e1aaeda']])
        ->glossaryEntry('asn', ['term' => 'ASN'])
        ->article('receive-a-delivery', [
            'source_references' => [RECEIVING_PLAN, 'ADR-0010', 'fmm#87'],
            'related_articles' => ['book-in-a-delivery', 'how-deliveries-work'],
        ], "## Check the ASN {#check-the-asn}\n\nLook at it.\n")
        ->article('book-in-a-delivery', [
            'source_references' => ['fmm#87'],
            'related_articles' => ['receive-a-delivery', 'how-deliveries-work'],
        ])
        ->article('how-deliveries-work', [
            'type' => 'Concept',
            'source_references' => ['ADR-0010'],
            'related_articles' => ['receive-a-delivery', 'book-in-a-delivery'],
        ]);
}

function sourcedMainProject(): FixtureMainProject
{
    return mainProject()
        ->glossary(['ASN' => 'An advance notice of a delivery.'])
        ->adr(10, 'inbound-shipments-purchase-orders-and-intake-choreography')
        ->plan(RECEIVING_PLAN, <<<'MD'
            - `/articles/receive-a-delivery` and its [ASN check](/articles/receive-a-delivery#check-the-asn).
            - Glossary Entries: `/glossary/asn`.
            MD)
        ->issue(87, 'Spec: Inbound Receiving, Purchase Orders & ASNs', <<<'MD'
            ## Problem Statement

            Receiving is on paper.

            ## Help content

            - /articles/receive-a-delivery, /articles/book-in-a-delivery
            MD, closed: true);
}

it('passes Source References that agree both ways', function () {
    $run = launchChecks(sourcedContent())->against(sourcedMainProject())->run();

    expect($run->failures())->toBe([])
        ->and($run->reports())->toBe([])
        ->and($run->exitCode)->toBe(Command::SUCCESS);
});

it('fails a cited plan, ADR or issue the main project doesn\'t have, on its line', function () {
    $content = sourcedContent()->article('move-a-container', [
        'source_references' => ['.junie/plans/Inbound/no-such-plan.md', 'ADR-0099', 'fmm#404', 'docs/../../secrets.md'],
        'related_articles' => ['receive-a-delivery', 'book-in-a-delivery'],
    ]);

    $run = launchChecks($content)->against(sourcedMainProject())->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe([
            'articles/move-a-container.md:14 [sources.exists] ".junie/plans/Inbound/no-such-plan.md": the main project has no such file',
            'articles/move-a-container.md:15 [sources.exists] "ADR-0099": the main project has no ADR numbered 0099 (docs/adr/0099-*.md)',
            'articles/move-a-container.md:16 [sources.exists] "fmm#404": gpl-ajackson/firstmilemanager has no issue 404',
            'articles/move-a-container.md:17 [sources.exists] "docs/../../secrets.md": the main project has no such file',
        ]);
});

it('fails a cited plan or spec that doesn\'t list the Article back', function () {
    $content = sourcedContent()->article('move-a-container', [
        'source_references' => [RECEIVING_PLAN, '.junie/plans/Warehouse/locations.md', 'fmm#52', 'fmm#87'],
        'related_articles' => ['receive-a-delivery', 'book-in-a-delivery'],
    ]);
    $mainProject = sourcedMainProject()
        ->plan('.junie/plans/Warehouse/locations.md', null)
        ->issue(52, 'Spec: Inventory Records & Stock Containers System', "## Problem Statement\n\nNo Help content yet.\n", closed: true);

    $run = launchChecks($content)->against($mainProject)->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe([
            'articles/move-a-container.md:14 [sources.listed-back] "'.RECEIVING_PLAN.'" doesn\'t list /articles/move-a-container in its Help content section',
            'articles/move-a-container.md:15 [sources.listed-back] ".junie/plans/Warehouse/locations.md" has no Help content section to list /articles/move-a-container in',
            'articles/move-a-container.md:16 [sources.listed-back] "fmm#52" has no Help content section to list /articles/move-a-container in',
            'articles/move-a-container.md:17 [sources.listed-back] "fmm#87" doesn\'t list /articles/move-a-container in its Help content section',
        ]);
});

it('needs no listing back from an ADR, another issue, a pull request or another document', function () {
    $content = sourcedContent()->article('move-a-container', [
        'source_references' => ['ADR-0010', 'fmm#200', 'fmm#260', 'docs/agents/demo-guide.md'],
        'related_articles' => ['receive-a-delivery', 'book-in-a-delivery'],
    ]);
    $mainProject = sourcedMainProject()
        ->issue(200, 'Containers can\'t be moved between zones', 'A bug report.', closed: true)
        ->issue(260, 'OB1: Warehouse Code', 'A pull request.', closed: true, pullRequest: true)
        ->file('docs/agents/demo-guide.md', "# Demo guide\n");

    $run = launchChecks($content)->against($mainProject)->run();

    expect($run->failures())->toBe([]);
});

it('reads a cited plan from the main project at the ref under test', function () {
    $atMain = sourcedMainProject()->plan(RECEIVING_PLAN, '- new: "Receive a delivery"');

    expect(launchChecks(sourcedContent())->against($atMain)->run()->failures())->toBe([
        'articles/receive-a-delivery.md:14 [sources.listed-back] "'.RECEIVING_PLAN.'" doesn\'t list /articles/receive-a-delivery in its Help content section',
    ])->and(launchChecks(sourcedContent())->against(sourcedMainProject())->run()->failures())->toBe([]);
});

it('fails help a cited plan or spec lists that doesn\'t exist, naming its line', function () {
    $content = sourcedContent()
        ->article('book-in-a-delivery', [
            'source_references' => ['fmm#87'],
            'related_articles' => ['receive-a-delivery', 'how-deliveries-work'],
            'former_addresses' => ['/articles/book-in'],
        ])
        ->article('old-way', ['status' => 'Retired', 'what_changed' => 'Deliveries are booked in for you now.']);
    $mainProject = sourcedMainProject()
        ->plan(RECEIVING_PLAN, <<<'MD'
            - `/articles/receive-a-delivery` and its [ASN check](/articles/receive-a-delivery#check-the-asn).
            - `/articles/receive-a-delivery#count-it`, `/articles/old-way` and `/articles/gone-missing`.
            - Glossary Entries: `/glossary/asn`, `/glossary/dock-door`; Client Glossary: `/client-glossary/asn`.
            - Not an address: `content/articles/receive-a-delivery.md`, nor `/articles/<name>`.
            MD)
        ->issue(87, 'Spec: Inbound Receiving, Purchase Orders & ASNs', <<<'MD'
            ## Help content

            - /articles/receive-a-delivery, /articles/book-in-a-delivery, /articles/book-in
            MD, closed: true);

    $run = launchChecks($content)->against($mainProject)->run();
    $atLaunch = launchChecks($content)->against($mainProject)->run(['--at-launch' => true]);

    $wrong = [
        'fmm#87 [sources.listed] Help content lists "/articles/book-in": "book-in" is now /articles/book-in-a-delivery: list it by its name now',
        'fmm:'.RECEIVING_PLAN.':8 [sources.listed] Help content lists "/articles/receive-a-delivery#count-it": "receive-a-delivery" has no Section named "count-it"',
        'fmm:'.RECEIVING_PLAN.':8 [sources.listed] Help content lists "/articles/old-way": "old-way" is Retired: list the Article that replaces it, or remove it',
    ];
    // Help not written yet is drafted area by area, so it fails only at launch.
    $notWrittenYet = [
        'fmm:'.RECEIVING_PLAN.':8 [sources.listed] Help content lists "/articles/gone-missing": there is no Article named "gone-missing"',
        'fmm:'.RECEIVING_PLAN.':9 [sources.listed] Help content lists "/glossary/dock-door": there is no Glossary Entry for "dock-door"',
        'fmm:'.RECEIVING_PLAN.':9 [sources.listed] Help content lists "/client-glossary/asn": there is no Client Glossary entry for "asn"',
    ];
    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe($wrong)
        ->and(array_values(array_filter($run->reports(), fn (string $line): bool => str_contains($line, '[sources.listed]'))))
        ->toBe(array_map(fn (string $line): string => $line.' (this fails at launch)', $notWrittenYet))
        ->and($atLaunch->failures('sources'))->toEqualCanonicalizing([...$wrong, ...$notWrittenYet]);
});

it('reports a built feature\'s plan or spec still listing a new: Article before launch, and fails it at launch', function () {
    $mainProject = sourcedMainProject()
        ->plan(RECEIVING_PLAN, <<<'MD'
            - `/articles/receive-a-delivery`.
            - new: "Receive a pallet", "Print a goods in label"
            MD)
        ->issue(87, 'Spec: Inbound Receiving, Purchase Orders & ASNs', <<<'MD'
            ## Help content

            - /articles/receive-a-delivery, /articles/book-in-a-delivery
            - Client tier, new: “Follow a delivery”
            MD, closed: true)
        ->file('.junie/plans/Outbound/orders.md', "# Orders\n\n## Help content\n\n- /articles/pack-an-order\n- new: \"Pick an order\"\n")
        ->issue(210, 'Spec: Outbound Order Management', "## Help content\n\n- /articles/pack-an-order\n- new: \"Enter an order\"\n");
    // Only a Draft cites the Outbound plan, and its spec is open: neither is built yet.
    $content = sourcedContent()->article('pack-an-order', [
        'status' => 'Draft',
        'source_references' => ['fmm#210', '.junie/plans/Outbound/orders.md'],
        'related_articles' => ['receive-a-delivery', 'how-deliveries-work'],
    ]);

    $before = launchChecks($content)->against($mainProject)->run();
    $atLaunch = launchChecks($content)->against($mainProject)->run(['--at-launch' => true]);

    $found = [
        'fmm#87 [sources.new-article] Help content still lists new: "Follow a delivery" for a built feature: write the Article and list it as /articles/<name>',
        'fmm:'.RECEIVING_PLAN.':8 [sources.new-article] Help content still lists new: "Receive a pallet" for a built feature: write the Article and list it as /articles/<name>',
        'fmm:'.RECEIVING_PLAN.':8 [sources.new-article] Help content still lists new: "Print a goods in label" for a built feature: write the Article and list it as /articles/<name>',
    ];
    expect($before->failures())->toBe([])
        ->and($before->exitCode)->toBe(Command::SUCCESS)
        ->and($before->failures())->toBe([])
        ->and($before->reports())->toBe(array_map(fn (string $line): string => $line.' (this fails at launch)', $found))
        ->and($atLaunch->exitCode)->toBe(Command::FAILURE)
        ->and($atLaunch->failures('sources'))->toBe($found)
        ->and($atLaunch->output)->toContain('at launch:');
});

it('holds the launch rules from going public on', function () {
    config(['help.going_public' => true]);
    $mainProject = sourcedMainProject()->plan(RECEIVING_PLAN, "- `/articles/receive-a-delivery`.\n- new: \"Receive a pallet\"");

    $run = launchChecks(sourcedContent())->against($mainProject)->run();

    expect($run->failures('sources'))->toBe([
        'fmm:'.RECEIVING_PLAN.':8 [sources.new-article] Help content still lists new: "Receive a pallet" for a built feature: write the Article and list it as /articles/<name>',
    ]);
});

it('reads issues from GitHub with the main project\'s read-only key, once each', function () {
    $run = launchChecks(sourcedContent())->against(sourcedMainProject())->run();

    expect($run->failures())->toBe([]);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/gpl-ajackson/firstmilemanager/issues/87'
        && $request->hasHeader('Authorization', 'Bearer fixture-read-token'));
});

it('says it didn\'t check issues when there is no key to read them with', function () {
    $run = launchChecks(sourcedContent())->against(sourcedMainProject())->withoutIssueToken()->run();

    expect($run->exitCode)->toBe(Command::SUCCESS)
        ->and($run->output)->toContain('Not run: Source References to the main project\'s issues (fmm#87): there is no FMM_READ_TOKEN to read them from GitHub with.');
    Http::assertNothingSent();
});

it('fails an issue GitHub couldn\'t answer for, so a reference is never passed unchecked', function () {
    Http::fake(['api.github.com/repos/gpl-ajackson/firstmilemanager/issues/*' => Http::response(['message' => 'Bad credentials'], 401)]);

    $run = launchChecks(sourcedContent())->against(sourcedMainProject())->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe([
            'articles/book-in-a-delivery.md:14 [sources.unreadable] "fmm#87" couldn\'t be read from GitHub (GitHub answered 401): re-run, or check FMM_READ_TOKEN can read the issues of gpl-ajackson/firstmilemanager',
            'articles/receive-a-delivery.md:16 [sources.unreadable] "fmm#87" couldn\'t be read from GitHub (GitHub answered 401): re-run, or check FMM_READ_TOKEN can read the issues of gpl-ajackson/firstmilemanager',
        ]);
});

it('leaves a Retired Article\'s Source References alone', function () {
    $content = sourcedContent()->article('old-way', [
        'status' => 'Retired',
        'what_changed' => 'Deliveries are booked in for you now.',
        'source_references' => ['.junie/plans/Inbound/gone.md'],
    ]);

    expect(launchChecks($content)->against(sourcedMainProject())->run()->failures())->toBe([]);
});

it('reads only the Help content section of a plan', function () {
    $mainProject = sourcedMainProject()->file(RECEIVING_PLAN, <<<'MD'
        # Receiving

        Settings: `DOCK_MODE=true` (new: switches on the dock screens), as /articles/gone-missing says.

        ## Help content

        - `/articles/receive-a-delivery`.

        ```
        ## Not a heading
        ```

        ### Glossary Entries

        - /glossary/asn

        ## Decisions

        - new: "Not a help item", and /articles/gone-missing.
        MD);

    $run = launchChecks(sourcedContent())->against($mainProject)->run();

    expect($run->failures())->toBe([])
        ->and($run->reports())->toBe([]);
});
