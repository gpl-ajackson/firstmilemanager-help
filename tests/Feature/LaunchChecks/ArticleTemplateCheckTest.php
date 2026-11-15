<?php

use Illuminate\Console\Command;
use Tests\Support\FixtureContent;
use Tests\Support\FixtureTemplates;

/*
 * Launch Check 1, template parts: the parts each Article Type's Article Template requires, in
 * its order, read from docs/writing/templates/ (fixture templates here). Fixture Articles'
 * bodies start on line 20.
 */

function howToTemplates(): FixtureTemplates
{
    return templates()
        ->template('How-to', [
            'In short',
            'Before you start',
            ['part' => 'Decide first', 'when_needed' => true],
            'Steps',
            'What happens next',
        ])
        ->template('Concept', ['In short']);
}

function templatedContent(string $body): FixtureContent
{
    return content()
        ->productAreas(['Inbound' => 'inbound.cluster.title'])
        ->articleOwners(['gpl-ajackson'])
        ->article('receive-a-delivery', [], $body);
}

const HOW_TO = <<<'MD'
    ## In short

    Scan the delivery in at the dock.

    ## Before you start {#before}

    You need to be a Warehouse Operative.

    ## Steps

    1. Scan the label.

    ## What happens next

    The delivery shows as received.
    MD;

it('passes an Article holding its template\'s parts in order, Section Names and all', function () {
    $run = launchChecks(templatedContent(HOW_TO))->templates(howToTemplates())->run();

    expect($run->exitCode)->toBe(Command::SUCCESS)
        ->and($run->failures())->toBe([]);
});

it('fails an Article missing a required part, naming where it belongs', function () {
    $body = str_replace("## Before you start {#before}\n\nYou need to be a Warehouse Operative.\n\n", '', HOW_TO);

    $run = launchChecks(templatedContent($body))->templates(howToTemplates())->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe([
            'articles/receive-a-delivery.md:24 [template.parts] the How-to template needs a "## Before you start" part, before "## Steps"',
        ]);
});

it('fails a required part missing at the end of the Article', function () {
    $body = str_replace("\n\n## What happens next\n\nThe delivery shows as received.", '', HOW_TO);

    $run = launchChecks(templatedContent($body))->templates(howToTemplates())->run();

    expect($run->failures())->toBe([
        'articles/receive-a-delivery.md:30 [template.parts] the How-to template needs a "## What happens next" part, after the parts above it',
    ]);
});

it('fails parts out of the template\'s order', function () {
    $body = <<<'MD'
        ## In short

        Scan the delivery in at the dock.

        ## Steps

        1. Scan the label.

        ## Before you start

        You need to be a Warehouse Operative.

        ## What happens next

        The delivery shows as received.
        MD;

    $run = launchChecks(templatedContent($body))->templates(howToTemplates())->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe([
            'articles/receive-a-delivery.md:24 [template.order] "## Steps" comes before "## Before you start"; the How-to template puts it after',
        ]);
});

it('fails a part the page builds from header data when its field is missing', function () {
    // The rebuild lets a Getting-started Article leave out related articles; this template doesn't.
    $templates = templates()->template('Getting-started', ['In short', ['part' => 'Related articles', 'field' => 'related_articles']]);
    $content = templatedContent("## In short\n\nScan it in.\n")
        ->article('build-your-warehouse', ['type' => 'Getting-started', 'related_articles' => null], "## In short\n\nBuild it.\n");

    $run = launchChecks($content)->templates($templates)->run();

    expect($run->failures())->toBe([
        'articles/build-your-warehouse.md:1 [template.parts] the Getting-started template needs "Related articles" (header data field related_articles)',
    ]);
});

it('names a header field the rebuild refuses once, as a header problem', function () {
    $templates = templates()->template('How-to', ['In short', ['part' => 'Related articles', 'field' => 'related_articles']]);
    $content = templatedContent("## In short\n\nScan it in.\n")
        ->article('receive-a-delivery', ['related_articles' => null], "## In short\n\nScan it in.\n");

    $run = launchChecks($content)->templates($templates)->run();

    expect($run->failures())->toBe([
        'articles/receive-a-delivery.md:1 [header.related_articles] is missing',
    ]);
});

it('doesn\'t count a heading inside a code block as a part', function () {
    $body = "## In short\n\nScan it in.\n\n```\n## Steps\n```\n";
    $templates = templates()->template('How-to', ['In short', 'Steps']);

    $run = launchChecks(templatedContent($body))->templates($templates)->run();

    expect($run->failures())->toBe([
        'articles/receive-a-delivery.md:26 [template.parts] the How-to template needs a "## Steps" part, after the parts above it',
    ]);
});

it('says, without failing, that there are no templates yet', function () {
    $run = launchChecks(templatedContent("Just text.\n"))->templates('/no/such/templates')->run();

    expect($run->exitCode)->toBe(Command::SUCCESS)
        ->and($run->output)->toContain('Not run: Article Template parts: there are no Article Templates in /no/such/templates yet.');
});

it('says which Article Types have no template yet', function () {
    $run = launchChecks(templatedContent("Just text.\n")->article('a-reference', ['type' => 'Reference'], "A table.\n"))
        ->templates(howToTemplates()->template('How-to', ['In short']))
        ->run();

    expect($run->failures())->toBe([
        'articles/receive-a-delivery.md:20 [template.parts] the How-to template needs a "## In short" part, after the parts above it',
    ])->and($run->output)->toContain(
        "Not run: Article Template parts of Reference Articles: there is no Reference template in {$run->templatesPath()} yet.",
    );
});

it('fails a template it can\'t read, naming the template', function () {
    $templates = templates()->file('how-to.md', "---\ntemplate: How-to\nparts:\n  - heading: In short\n---\n");

    $run = launchChecks(templatedContent("Text.\n"))->templates($templates)->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toContain(
            "{$templates->path()}/how-to.md:1 [template.format] each part needs \"part\" (its heading), and may give \"field\" (header data it comes from) and \"when_needed: true\"",
        );
});

it('leaves templates that aren\'t Article Templates to their own checks', function () {
    $templates = howToTemplates()->file('glossary-entry.md', "---\ntemplate: Glossary Entry\nparts:\n  - part: The term\n---\n");

    $run = launchChecks(templatedContent(HOW_TO))->templates($templates)->run();

    expect($run->failures())->toBe([]);
});
