<?php

use Illuminate\Console\Command;
use Tests\Support\FixtureContent;
use Tests\Support\LaunchCheckRun;
use Tests\Support\ReaderPage;

/*
 * Launch Check 10, Findability (spec #30, user stories 122 and 170): every question in the
 * Findability Set, in a reader's own words, shows its named answer (an Article, an Article's
 * Section or a Glossary Entry) in the top three results, searched against the pull request's
 * own content in a fresh Meilisearch index.
 */

/** A small help centre, as a pull request might leave it, with the readers' questions it answers. */
function aFindableHelpCentre(): FixtureContent
{
    return content()
        ->productAreas(['Inbound' => 'inbound.cluster.title'])
        ->articleOwners(['gpl-ajackson'])
        ->article('receive-a-delivery', ['title' => 'Receive a delivery', 'summary' => 'How to book a delivery in at goods in.'],
            "Scan each pallet as it comes off the lorry.\n\n## The location is full {#location-full}\n\nChoose another location.\n")
        ->article('receive-a-delivery-with-damage', ['title' => 'Receive a delivery with damage', 'summary' => 'What to do when stock arrives damaged.'])
        ->article('receive-a-delivery-without-an-asn', ['title' => 'Receive a delivery without an ASN', 'summary' => 'How to book in stock nobody told you about.'])
        ->article('receive-a-delivery-for-a-new-client', ['title' => 'Receive a delivery for a new client', 'summary' => 'How to book in a client\'s first stock.'])
        ->article('quarantine-stock', ['title' => 'Quarantine stock', 'summary' => 'How to keep stock out of picking until it is checked.'])
        ->article('location-cant-take-this-container', [
            'title' => 'A location can\'t take a container',
            'summary' => 'Why a location refuses a container, and what to do.',
            'type' => 'Troubleshooting',
            'messages' => ['Location [Location] can\'t take this container'],
        ])
        ->glossaryEntry('location', ['other_names' => ['bin']], "A Location is one place in the warehouse where stock is kept.\n")
        ->findabilitySet([
            'receive a delivery' => '/articles/receive-a-delivery',
            'location full' => '/articles/receive-a-delivery#location-full',
            'quarentine' => '/articles/quarantine-stock',
            'Location A1-01-1 can\'t take this container' => '/articles/location-cant-take-this-container',
            'bin' => '/glossary/location',
        ]);
}

function findabilityRun(FixtureContent $content, array $options = []): LaunchCheckRun
{
    return launchChecks($content)
        ->against(mainProject()->glossary(['Location' => 'A place stock is kept.']))
        ->run($options);
}

/** @return list<string> */
function findabilityLines(LaunchCheckRun $run): array
{
    return array_values(array_filter([...$run->failures(), ...$run->reports()], fn (string $line): bool => str_contains($line, '[findability.')));
}

it('passes a Findability Set whose questions all show their answers in the top three', function () {
    $run = findabilityRun(aFindableHelpCentre());

    expect($run->failures())->toBe([])
        ->and($run->exitCode)->toBe(Command::SUCCESS)
        ->and(findabilityLines($run))->toBe([])
        ->and($run->output)->not->toContain('Not run: Findability');
});

it('fails a question whose answer drops below third, naming the question and where the answer came', function () {
    $content = aFindableHelpCentre()->article('receive-a-delivery', ['title' => 'Book goods in', 'summary' => 'How to book a delivery in at goods in.'],
        "Receive a delivery by scanning each pallet as it comes off the lorry.\n\n## The location is full {#location-full}\n\nChoose another location.\n");

    $run = findabilityRun($content);

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and(findabilityLines($run))->toBe([
            'findability-set.yaml:1 [findability.top-three] "receive a delivery" should show /articles/receive-a-delivery in the top three results, '
            .'but it came 4th (search showed /articles/receive-a-delivery-for-a-new-client, /articles/receive-a-delivery-with-damage, /articles/receive-a-delivery-without-an-asn first)',
        ]);
});

it('fails a question naming an answer search can never show', function (array $set, string $problem) {
    $content = aFindableHelpCentre()
        ->article('count-stock', ['status' => 'Draft'])
        ->article('move-stock', ['former_addresses' => ['/articles/shift-stock']])
        ->findabilitySet($set);

    $run = findabilityRun($content);

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and(findabilityLines($run))->toBe(['findability-set.yaml:1 [findability.answer] '.$problem]);
})->with([
    'a missing Article' => [['how do I book in' => '/articles/book-in'], '"how do I book in" names /articles/book-in, but there is no Article named "book-in"'],
    'a Draft' => [['count stock' => '/articles/count-stock'], '"count stock" names /articles/count-stock, but "count-stock" is Draft, and search shows only Published Articles'],
    'a former name' => [['shift stock' => '/articles/shift-stock'], '"shift stock" names /articles/shift-stock, but "shift-stock" is now /articles/move-stock: name it by its name now'],
    'a missing Section' => [['dock full' => '/articles/receive-a-delivery#dock-full'], '"dock full" names /articles/receive-a-delivery#dock-full, but "receive-a-delivery" has no Section named "dock-full"'],
    'a missing Glossary Entry' => [['tote' => '/glossary/stock-container'], '"tote" names /glossary/stock-container, but there is no Glossary Entry for "stock-container"'],
    'a missing Client Glossary entry' => [['bin' => '/client-glossary/location'], '"bin" names /client-glossary/location, but there is no Client Glossary entry for "location"'],
]);

it('fails a Section answer when search opens the Article somewhere else', function () {
    $run = findabilityRun(aFindableHelpCentre()->findabilitySet(['receive a delivery' => '/articles/receive-a-delivery#location-full']));

    expect(findabilityLines($run))->toBe([
        'findability-set.yaml:1 [findability.top-three] "receive a delivery" should show /articles/receive-a-delivery#location-full in the top three results, '
        .'but it came 1st as /articles/receive-a-delivery, which doesn\'t open at that Section '
        .'(search showed /articles/receive-a-delivery, /articles/receive-a-delivery-for-a-new-client, /articles/receive-a-delivery-with-damage first)',
    ]);
});

it('fails a question search finds nothing for', function () {
    $run = findabilityRun(aFindableHelpCentre()->findabilitySet(['hazardous goods' => '/articles/quarantine-stock']));

    expect(findabilityLines($run))->toBe([
        'findability-set.yaml:1 [findability.top-three] "hazardous goods" should show /articles/quarantine-stock in the top three results, but it isn\'t among the results (search found nothing)',
    ]);
});

it('fails a Findability Set it can\'t read, naming the entry\'s line', function () {
    $content = aFindableHelpCentre()->file('findability-set.yaml', <<<'YAML'
        - question: receive a delivery
          answer: /articles/receive-a-delivery
        - question: bin
        - question: quarentine
          answer: articles/quarantine-stock
        - question: location full
          answer: /articles/receive-a-delivery#location-full
          asked_by: Sarah
        - answer: /glossary/location
        YAML);

    $run = findabilityRun($content);

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and(findabilityLines($run))->toBe([
            'findability-set.yaml:3 [findability.format] "bin": answer is missing: /articles/<name>, /articles/<name>#<Section Name>, /glossary/<term> or /client-glossary/<term>',
            'findability-set.yaml:4 [findability.format] "quarentine": answer "articles/quarantine-stock" must be the answer\'s address: /articles/<name>, /articles/<name>#<Section Name>, /glossary/<term> or /client-glossary/<term>',
            'findability-set.yaml:6 [findability.format] asked_by is not a Findability Set field (known fields: question, answer)',
            'findability-set.yaml:9 [findability.format] question is missing: the question in a reader\'s own words',
        ]);
});

it('fails a Findability Set that isn\'t YAML or isn\'t a list', function (string $contents, string $line) {
    $run = findabilityRun(aFindableHelpCentre()->file('findability-set.yaml', $contents));

    expect(findabilityLines($run))->toHaveCount(1)
        ->and(findabilityLines($run)[0])->toStartWith($line);
})->with([
    'not YAML' => ["- question: bin\n  answer: [/glossary/location\n", 'findability-set.yaml:3 [findability.format] is not valid YAML'],
    'not a list' => ["bin: /glossary/location\n", 'findability-set.yaml:1 [findability.format] must be a list of questions'],
]);

it('says it didn\'t run without a Findability Set before launch, and fails without one at launch', function (?string $contents) {
    $content = aFindableHelpCentre();
    $contents === null ? unlink($content->path().'/findability-set.yaml') : $content->file('findability-set.yaml', $contents);
    $missing = $contents === null
        ? 'there is no Findability Set: write findability-set.yaml in the content folder, each reader\'s question with its answer'
        : 'lists no questions';

    $before = findabilityRun($content);
    $atLaunch = findabilityRun($content, ['--at-launch' => true]);

    expect($before->exitCode)->toBe(Command::SUCCESS)
        ->and(findabilityLines($before))->toBe([])
        ->and($before->output)->toContain("Not run: Findability: {$missing} (this fails at launch).")
        ->and($atLaunch->exitCode)->toBe(Command::FAILURE)
        ->and(findabilityLines($atLaunch))->toBe(["findability-set.yaml [findability.set] {$missing}"]);
})->with([
    'no file' => [null],
    'an empty file' => [''],
]);

it('searches the content under test in a fresh index, leaving the help centre\'s own search and pages alone', function () {
    content()->article('count-stock', ['title' => 'Count stock', 'summary' => 'How to count what is on the shelf.'])->publish();

    $run = findabilityRun(aFindableHelpCentre());

    expect($run->failures())->toBe([]);
    $page = ReaderPage::of($this->get('/search?q=count+stock')->assertOk());
    expect($page->texts('.results .result-title'))->toBe(['Count stock']);
    expect(ReaderPage::of($this->get('/search?q=quarantine')->assertOk())->has('.results'))->toBeFalse();
    $this->get('/articles/count-stock')->assertOk();
    $this->get('/articles/quarantine-stock')->assertNotFound();
});

it('fails when Meilisearch can\'t be used, rather than passing the questions unsearched', function () {
    config(['help.search.host' => 'http://127.0.0.1:9']);

    $run = findabilityRun(aFindableHelpCentre());

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and(findabilityLines($run))->toHaveCount(1)
        ->and(findabilityLines($run)[0])->toStartWith('findability-set.yaml [findability.search] the questions couldn\'t be searched: Meilisearch at http://127.0.0.1:9 can\'t be used');
});
