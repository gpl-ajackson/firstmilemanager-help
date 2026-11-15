<?php

use Illuminate\Console\Command;
use Tests\Support\FixtureContent;
use Tests\Support\LaunchCheckRun;

/*
 * The writing foundation (#70) as the Launch Checks read it: this repository's own Article
 * Templates (docs/writing/templates/) and Style Guide (docs/writing/style-guide.md), run over
 * fixture Articles drafted by them.
 */

function foundationTemplates(): string
{
    return base_path('docs/writing/templates');
}

function foundationContent(string $name, array $header, string $body): FixtureContent
{
    return content()
        ->productAreas(['Inbound' => 'inbound.cluster.title'])
        ->articleOwners(['gpl-ajackson'])
        ->article($name, $header, $body);
}

/** The run's failures for one Article, leaving out the fixture's example related articles. */
function failuresFor(string $name, LaunchCheckRun $run): array
{
    return array_values(array_filter($run->failures(), fn (string $line): bool => str_starts_with($line, "articles/{$name}.md")));
}

const DRAFTED_HOW_TO = <<<'MD'
    ## In short

    Scan each item into a container at the dock. Select **Complete** when the delivery is in.

    ## Before you start

    You need to be an Admin or a Warehouse Operative.

    ## Steps

    1. Select **Inbound › Shipments** and open the delivery.
    2. Select **Open Dock Terminal**.

    ## What happens next

    The delivery shows as received.
    MD;

it('passes a How-to drafted by the How-to template', function () {
    $run = launchChecks(foundationContent('receive-a-delivery', [], DRAFTED_HOW_TO))
        ->templates(foundationTemplates())
        ->run();

    expect(failuresFor('receive-a-delivery', $run))->toBe([])
        ->and($run->output)->not->toContain('there is no How-to template');
});

it('passes an Article of every other type drafted by its template', function (string $type, array $header, string $body) {
    $run = launchChecks(foundationContent('drafted', ['type' => $type, ...$header], $body))
        ->templates(foundationTemplates())
        ->run();

    expect(failuresFor('drafted', $run))->toBe([])
        ->and($run->output)->not->toContain("there is no {$type} template");
})->with([
    'Getting-started' => ['Getting-started', ['path_position' => 3, 'related_articles' => null], <<<'MD'
        ## In short

        By the end of this step, your warehouse has its zones and every location, ready to label.

        ## Decide first

        Choose your warehouse's code now. It is fixed once a delivery has been booked in under it.

        ## Checklist

        1. Add your warehouse and its zones with [](article:example-concept). Do this first, because locations sit in zones.

        ## What happens next

        You're done when your locations show in **Warehouses › Locations**.
        MD],
    'Concept' => ['Concept', [], <<<'MD'
        ## In short

        A warehouse is laid out as zones, aisles, racks, shelves and locations. Each location has a code you scan.

        ## Zones and aisles

        Trentside's Leeds warehouse has three zones.
        MD],
    'Reference' => ['Reference', [], <<<'MD'
        ## In short

        This lists every code style, for when you set up locations.

        ## Code styles

        | Style | Example |
        | --- | --- |
        | Short | `A1A1` |
        MD],
    'Troubleshooting' => ['Troubleshooting', ['messages' => ['Location [location] is quarantined']], <<<'MD'
        ## In short

        The stock is quarantined, so it can only go to a quarantine location. Move it to one.

        ## The stock is quarantined {#quarantined}

        Quarantined stock goes only to a quarantine location. Scan a quarantine location instead.
        MD],
    'FAQ' => ['FAQ', [], <<<'MD'
        ## In short

        Yes. A client can have several contacts, each with its own purposes.
        MD],
]);

it('requires "In short" in every Article Template', function (string $type) {
    $run = launchChecks(foundationContent('drafted', ['type' => $type], "## Something else\n\nNo answer first.\n"))
        ->templates(foundationTemplates())
        ->run();

    $inShort = array_filter(failuresFor('drafted', $run), fn (string $line): bool => str_contains($line, "[template.parts] the {$type} template needs a \"## In short\" part"));

    expect($inShort)->toHaveCount(1);
})->with(['How-to', 'Getting-started', 'Concept', 'Reference', 'Troubleshooting', 'FAQ']);

it('fails a How-to missing the template\'s "Before you start"', function () {
    $body = str_replace("## Before you start\n\nYou need to be an Admin or a Warehouse Operative.\n\n", '', DRAFTED_HOW_TO);

    $run = launchChecks(foundationContent('receive-a-delivery', [], $body))
        ->templates(foundationTemplates())
        ->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and(failuresFor('receive-a-delivery', $run))->toBe([
            'articles/receive-a-delivery.md:24 [template.parts] the How-to template needs a "## Before you start" part, before "## Steps"',
        ]);
});

it('fails the words the Style Guide bans, saying what to write instead', function () {
    $body = "## In short\n\nSimply click **Despatch**, e.g. at the bench.\n";

    $run = launchChecks(foundationContent('receive-a-delivery', ['type' => 'FAQ'], $body))
        ->styleGuide(null, (string) file_get_contents(base_path('docs/writing/style-guide.md')))
        ->run();

    expect(array_values(array_filter(failuresFor('receive-a-delivery', $run), fn (string $line): bool => str_contains($line, '[words.'))))->toBe([
        'articles/receive-a-delivery.md:22 [words.banned] "Simply" is a word the Style Guide bans',
        'articles/receive-a-delivery.md:22 [words.banned] "click" is a word the Style Guide bans: say "select"',
        'articles/receive-a-delivery.md:22 [words.banned] "Despatch" is a word the Style Guide bans: say "dispatch"',
        'articles/receive-a-delivery.md:22 [words.banned] "e.g." is a word the Style Guide bans: say "such as"',
    ]);
});

it('bans "we", the plan\'s filler words and the brand\'s adjectives', function (string $word) {
    $run = launchChecks(foundationContent('receive-a-delivery', ['type' => 'FAQ'], "## In short\n\nThis is {$word} here.\n"))
        ->styleGuide(null, (string) file_get_contents(base_path('docs/writing/style-guide.md')))
        ->run();

    expect(implode("\n", failuresFor('receive-a-delivery', $run)))->toContain("[words.banned] \"{$word}\" is a word the Style Guide bans");
})->with(['we', 'please', 'just', 'easy', 'easily', 'note that', 'i.e.', 'etc.', 'tap', 'press', 'hit', 'the system', 'the app', 'the software', 'seamless', 'powerful', 'revolutionary', 'best-in-class', 'robust', 'cutting-edge', 'administrator']);
