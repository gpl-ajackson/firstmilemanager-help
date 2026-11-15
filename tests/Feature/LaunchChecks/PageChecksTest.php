<?php

use App\LaunchChecks\Privacy\PrivacyCheck;
use Illuminate\Console\Command;
use Tests\Support\FaultyPages;
use Tests\Support\FixtureContent;
use Tests\Support\LaunchCheckRun;
use Tests\Support\RealHelpCentre;

/*
 * The page checks (spec #30, seam 2 driving seam 1's browser; user stories 89 to 93, 123 and
 * 124): with --pages, headless Chromium opens every published page of the content under test,
 * served by the app itself from that content, not a sample.
 *
 * Launch Check 7, Accessible and fits: an axe WCAG 2.2 AA scan and no sideways scrolling at
 * 320px, 390px and 1280px, light and dark; controls at least 44px, the search box 52px.
 * Launch Check 8, Privacy: no cookie and no storage on any page; a test Search saves only
 * markers; a test vote and "Still stuck?" leave nothing about the reader in any record.
 * Launch Check 11, Fast on the floor: each template usable within 3 seconds on slow 4G.
 */

/** A small help centre with a page of every template: each Article Type, both tiers, both Glossaries, a Release Note, a retired notice. */
function everyKindOfPage(): FixtureContent
{
    return content()
        ->productAreas(['Inbound' => 'inbound.cluster.title', 'Warehouses' => 'warehouses.cluster.title'])
        ->articleOwners(['gpl-ajackson'])
        ->article('start-with-your-warehouse', [
            'type' => 'Getting-started', 'product_area' => 'Warehouses', 'title' => 'Start with your warehouse',
            'summary' => 'Set up your first warehouse and its locations.', 'related_articles' => null,
        ], "## In short\n\nAdd your warehouse, then its locations.\n\n1. Open **Warehouses**.\n2. Select **New warehouse**.\n3. Enter its name and address.\n")
        ->article('receive-a-delivery', [
            'title' => 'Receive a delivery', 'summary' => 'Book a delivery in at goods in, pallet by pallet.',
            'team_roles' => ['Admin', 'Warehouse Operative'], 'related_articles' => ['how-goods-in-works', 'delivery-statuses'],
        ], "## In short\n\nScan each pallet as it comes off the lorry.\n\n1. Open **Inbound**.\n2. Scan the pallet's label.\n3. Choose its [location](term:location).\n\n"
            ."## The location is full {#location-full}\n\nChoose another location, or read [how goods in works](article:how-goods-in-works).\n\n"
            ."| Code | Means |\n|---|---|\n| GI | Goods in |\n| QC | Quality check |\n")
        ->article('how-goods-in-works', ['type' => 'Concept', 'title' => 'How goods in works', 'related_articles' => ['receive-a-delivery', 'delivery-statuses']],
            "Goods in is where deliveries arrive. Each pallet is scanned to a location.\n")
        ->article('delivery-statuses', ['type' => 'Reference', 'title' => 'Delivery statuses', 'related_articles' => ['receive-a-delivery', 'how-goods-in-works']],
            "| Status | Means |\n|---|---|\n| Expected | Booked, not arrived |\n| Received | Every pallet scanned |\n")
        ->article('location-is-quarantined', [
            'type' => 'Troubleshooting', 'title' => 'A location is quarantined', 'messages' => ['Location [Location] is quarantined'],
            'related_articles' => ['receive-a-delivery', 'how-goods-in-works'],
        ], "## In short\n\nChoose a location that isn't quarantined.\n\n## Someone quarantined it {#quarantined}\n\nAsk an Admin to release it.\n")
        ->article('can-i-split-a-delivery', ['type' => 'FAQ', 'title' => 'Can I split a delivery?', 'related_articles' => ['receive-a-delivery', 'delivery-statuses']],
            "Yes. Receive the pallets that arrived; the rest stay expected.\n")
        ->article('follow-your-deliveries', ['audience_tier' => 'Client', 'title' => 'Follow your deliveries'],
            "Each [delivery](term:delivery) shows its status as your 3PL books it in.\n")
        ->article('book-in-by-hand', ['status' => 'Retired', 'what_changed' => 'Deliveries are booked in by scanning now.'])
        ->glossaryEntry('location', ['other_names' => ['bin']], "A Location is one place in the warehouse where stock is kept.\n")
        ->clientGlossaryEntry('delivery', [], "A delivery is stock arriving at your 3PL's warehouse.\n")
        ->releaseNote('2026-11-14', ['summary' => 'What changed in Inbound.']);
}

function pageChecks(FixtureContent $content, array $options = []): LaunchCheckRun
{
    return launchChecks($content)
        ->against(mainProject()->glossary(['Location' => 'One place in the warehouse.', 'Delivery' => 'Stock arriving.']))
        ->run(['--pages' => true, ...$options]);
}

/** @return list<string> The page checks' failures: Launch Checks 7, 8 and 11. */
function pageFailures(LaunchCheckRun $run): array
{
    return [...$run->failures('accessible'), ...$run->failures('privacy'), ...$run->failures('fast')];
}

it('passes every page of a clean help centre, of every template', function () {
    $run = pageChecks(everyKindOfPage());

    expect(pageFailures($run))->toBe([])
        ->and($run->exitCode)->toBe(Command::SUCCESS)
        ->and($run->output)->not->toContain('Not run: Accessible')
        ->and($run->output)->not->toContain('Not run: Privacy');
});

/** The smallest help centre: one Article (and the Articles it is related to by default). */
function oneArticle(?string $body = null): FixtureContent
{
    return content()
        ->productAreas(['Inbound' => 'inbound.cluster.title'])
        ->articleOwners(['gpl-ajackson'])
        ->article('receive-a-delivery', ['title' => 'Receive a delivery'], $body);
}

it('fails a page with an accessibility fault, naming the page and the rule, and a page that scrolls sideways at 320px', function () {
    FaultyPages::make(['/glossary' => FaultyPages::TOO_WIDE]);

    $run = pageChecks(oneArticle("Scan each pallet. [](https://firstmilemanager.co.uk/product)\n"));

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures('accessible'))->toBe([
            '/glossary [accessible.sideways-scrolling] scrolls sideways at 320px and 390px, light and dark: the page is 96px wider than the screen (too wide: main.wrap > div.banner)',
            '/articles/receive-a-delivery [accessible.link-name] Links must have discernible text (1 element: .prose > p > a) at every width',
        ]);
});

it('fails a page that sets a cookie or keeps local storage, and a Search kept as typed with the reader\'s browser', function () {
    FaultyPages::make([
        '/whats-new' => FaultyPages::COOKIE,
        '/glossary' => FaultyPages::LOCAL_STORAGE,
        '/search' => FaultyPages::KEEPS_THE_READER,
    ]);

    $run = pageChecks(oneArticle());

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures('privacy'))->toHaveCount(4)
        ->and(array_slice($run->failures('privacy'), 0, 2))->toBe([
            '/glossary [privacy.storage] keeps local storage (1 item) on the reader\'s device after opening the page: a public page keeps nothing there',
            '/whats-new [privacy.cookie] sets a cookie after opening the page (seen): a public page sets none',
        ])
        ->and($run->failures('privacy')[2])->toStartWith('/search [privacy.search] the test Search "'.PrivacyCheck::TEST_SEARCH.'" was saved as "'.PrivacyCheck::TEST_SEARCH.' Mozilla/5.0')
        ->and($run->failures('privacy')[3])->toBe('records [privacy.records] search_records keeps the reader\'s user agent after a test reader searched, voted and asked "Still stuck?": no record holds anything about the reader');
});

it('fails a template slower than 3 seconds on the slow 4G profile', function () {
    // About 700 KB of words: over 3.5 seconds at 1.44 Mbit/s before anything else arrives.
    $run = pageChecks(oneArticle(str_repeat('Scan each pallet as it comes off the lorry, then put it away. ', 11_000)."\n"));

    expect($run->failures('fast'))->toHaveCount(1)
        ->and($run->failures('fast')[0])->toMatch('~^/articles/receive-a-delivery \[fast\.slow\] The How-to Article template took \d+\.\d seconds to be usable on a slow 4G connection, over the 3 second limit \(its heaviest page: [\d,]+ KB in \d+ requests\)$~');
});

it('runs the page checks only when asked, as CI asks', function () {
    $run = launchChecks(oneArticle())->run();

    expect($run->output)->toContain('Not run: Accessible and fits (Launch Check 7)')
        ->and($run->output)->toContain('Not run: Privacy (Launch Check 8)')
        ->and($run->output)->toContain('Not run: Fast on the floor (Launch Check 11)');
});

afterEach(fn () => RealHelpCentre::stopAll());

it('times each template at the real address too, through Cloudflare Access with its service token', function () {
    $real = RealHelpCentre::start();
    config(['help.access.client_id' => 'fixture-id.access', 'help.access.client_secret' => 'fixture-secret']);

    $run = launchChecks(oneArticle())->run(['--speed-at' => $real->url]);

    expect($run->failures('fast'))->toBe([])
        ->and($real->tokensSeen())->toContain('fixture-id.access fixture-secret /', 'fixture-id.access fixture-secret /articles/receive-a-delivery')
        ->and($run->output)->toContain('Not run: Accessible and fits (Launch Check 7)');
});

it('fails at the real address when Cloudflare Access answers instead of the help centre', function () {
    $real = RealHelpCentre::start();

    $run = launchChecks(oneArticle())->run(['--speed-at' => $real->url]);

    expect($run->failures('fast'))->toContain(
        "{$real->url}/ [fast.real-address] the home template was answered by localhost, not the help centre (Cloudflare Access's sign-in?): set CF_ACCESS_CLIENT_ID and CF_ACCESS_CLIENT_SECRET to the Access service token",
    )->and($real->tokensSeen())->toBe([]);
});
