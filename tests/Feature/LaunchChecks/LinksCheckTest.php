<?php

use Illuminate\Console\Command;
use Tests\Support\FixtureContent;
use Tests\Support\FixtureMainProject;

/*
 * Launch Check 3, Links (spec #30, seam 2): every link by name and Section Name resolves for
 * its page's readers, Client-tier pages link only to Client-tier pages, and Client-tier pages
 * never name Stockist, in their words or their pictures' alt text. Fixture Articles' header data
 * takes lines 1 to 19 ("---" included), so a body starts on line 20.
 */

/** The main project, with GLOSSARY.md's terms for the fixture's Glossary Entries. */
function linkedProject(): FixtureMainProject
{
    return mainProject()->glossary(['ASN' => 'An advance notice of a delivery.', 'Delivery' => 'Stock arriving at the warehouse.']);
}

function linkedContent(): FixtureContent
{
    return content()
        ->productAreas(['Inbound' => 'inbound.cluster.title'])
        ->articleOwners(['gpl-ajackson'])
        ->glossaryEntry('asn', ['term' => 'ASN'])
        ->clientGlossaryEntry('delivery')
        ->article('receive-a-delivery', ['related_articles' => ['book-in-a-delivery', 'how-deliveries-work']],
            "## Check the ASN {#check-the-asn}\n\nRead [how deliveries work](article:how-deliveries-work) and the [ASN](term:asn).\n")
        ->article('book-in-a-delivery', ['related_articles' => ['receive-a-delivery', 'how-deliveries-work']],
            "Then [check the ASN](article:receive-a-delivery#check-the-asn).\n")
        ->article('how-deliveries-work', ['type' => 'Concept', 'related_articles' => ['receive-a-delivery', 'book-in-a-delivery']])
        ->article('follow-your-deliveries', ['audience_tier' => 'Client', 'related_articles' => ['see-your-stock', 'your-account']],
            "Each [delivery](term:delivery) shows its status. See [your stock](article:see-your-stock).\n")
        ->article('see-your-stock', ['audience_tier' => 'Client', 'related_articles' => ['follow-your-deliveries', 'your-account']])
        ->article('your-account', ['audience_tier' => 'Client', 'related_articles' => ['follow-your-deliveries', 'see-your-stock']]);
}

it('passes links that all resolve for their readers', function () {
    $run = launchChecks(linkedContent())->against(linkedProject())->run();

    expect($run->failures())->toBe([])
        ->and($run->exitCode)->toBe(Command::SUCCESS);
});

it('fails a link to a missing Article, Section or term, with its file and line', function () {
    $run = launchChecks(linkedContent()->article('move-a-container', ['related_articles' => ['receive-a-delivery', 'book-in-a-delivery']], <<<'MD'
        ## Steps {#steps}

        Read [booking in](article:book-in) and [the checks](article:receive-a-delivery#checks).
        Then [go back](#start) and look up the [Location](term:location).
        MD))->against(linkedProject())->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe([
            'articles/move-a-container.md:22 [links.resolves] link "article:book-in": there is no Article named "book-in"',
            'articles/move-a-container.md:22 [links.resolves] link "article:receive-a-delivery#checks": "receive-a-delivery" has no Section named "checks"',
            'articles/move-a-container.md:23 [links.resolves] link "#start": this Article has no Section named "start"',
            'articles/move-a-container.md:23 [links.resolves] link "term:location": there is no Glossary Entry for "location"',
        ]);
});

it('fails a related article that leads nowhere, on its header data line', function () {
    $run = launchChecks(linkedContent()->article('move-a-container', ['related_articles' => ['receive-a-delivery', 'gone-missing']]))->against(linkedProject())->run();

    expect($run->failures())->toBe([
        'articles/move-a-container.md:17 [links.resolves] related_articles "gone-missing": there is no Article named "gone-missing"',
    ]);
});

it('fails a Client-tier page that links to a staff page, by name or by path', function () {
    $run = launchChecks(linkedContent()->article('your-account', ['audience_tier' => 'Client', 'related_articles' => ['follow-your-deliveries', 'receive-a-delivery']], <<<'MD'
        See [receiving](article:receive-a-delivery) and the [ASN](term:asn).
        Or [the Inbound area](/areas/inbound), [the path](/getting-started) and [the Glossary](/glossary).
        Your words are in [the Client Glossary](/client-glossary).
        MD))->against(linkedProject())->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe([
            'articles/your-account.md:15 [links.client-tier] related_articles "receive-a-delivery": "receive-a-delivery" is for Staff; a Client-tier page links only to Client-tier Articles',
            'articles/your-account.md:18 [links.client-tier] link "article:receive-a-delivery": "receive-a-delivery" is for Staff; a Client-tier page links only to Client-tier Articles',
            'articles/your-account.md:18 [links.resolves] link "term:asn": there is no Client Glossary entry for "asn"',
            'articles/your-account.md:19 [links.client-tier] link "/areas/inbound": is not a Client-tier page; a Client-tier page links only to Client-tier pages',
            'articles/your-account.md:19 [links.client-tier] link "/getting-started": is not a Client-tier page; a Client-tier page links only to Client-tier pages',
            'articles/your-account.md:19 [links.client-tier] link "/glossary": is not a Client-tier page; a Client-tier page links only to Client-tier pages',
        ]);
});

it('fails a Client-tier page that names Stockist in its words, a Picture\'s alt text or a link\'s address', function () {
    $content = linkedContent()
        ->picture('client-account', ['alt' => 'The STOCKIST account page, with your company name outlined.'])
        ->article('your-account', [
            'audience_tier' => 'Client',
            'title' => 'Your Stockist account',
            'related_articles' => ['follow-your-deliveries', 'see-your-stock'],
        ], <<<'MD'
        ## In short {#stockist-free}

        Your 3PL keeps your account.
        ![](picture:client-account)
        Ask about [stockists](https://stockistwms.co.uk) or [your account](article:see-your-stock).
        Sign in to `Stockist` first.
        MD);

    $run = launchChecks($content)->against(linkedProject())->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe([
            'articles/your-account.md:2 [links.stockist] "Stockist" in its title; a Client-tier page never names Stockist: say "your courier"',
            'articles/your-account.md:21 [links.stockist] "Stockist" in the alt text of Picture "client-account" (its Recipe); a Client-tier page never names Stockist: say "your courier"',
            'articles/your-account.md:22 [links.stockist] "Stockist" in its words; a Client-tier page never names Stockist: say "your courier"',
            'articles/your-account.md:22 [links.stockist] "Stockist" in a link\'s address; a Client-tier page never names Stockist: say "your courier"',
            'articles/your-account.md:23 [links.stockist] "Stockist" in its words; a Client-tier page never names Stockist: say "your courier"',
        ]);
});

it('lets a Joint-only page name Stockist, and a Client-tier page name it only where readers never see it', function () {
    $run = launchChecks(linkedContent()
        ->article('stockist-deliveries', ['summary' => 'How Stockist receives a delivery, in one sentence.', 'related_articles' => ['book-in-a-delivery', 'how-deliveries-work'], 'joint_only' => true],
            "## Check the ASN {#check-the-asn}\n\nStockist checks it.\n")
        ->picture('client-stockist-account', ['alt' => 'Your account page, with your company name outlined.'])
        ->article('your-account', ['audience_tier' => 'Client', 'related_articles' => ['follow-your-deliveries', 'see-your-stock']],
            "![](picture:client-stockist-account)\n"))
        ->against(linkedProject())->run();

    expect($run->failures())->toBe([]);
});

it('fails Stockist in a Client-tier Troubleshooting Article\'s messages and summary', function () {
    $run = launchChecks(linkedContent()->article('cant-sign-in', [
        'audience_tier' => 'Client',
        'type' => 'Troubleshooting',
        'summary' => 'What to do when Stockist won\'t let you sign in.',
        'messages' => ['Your password is wrong', 'Stockist is down for maintenance'],
        'related_articles' => ['follow-your-deliveries', 'see-your-stock'],
    ]))->against(linkedProject())->run();

    expect($run->failures())->toBe([
        'articles/cant-sign-in.md:3 [links.stockist] "Stockist" in its summary; a Client-tier page never names Stockist: say "your courier"',
        'articles/cant-sign-in.md:18 [links.stockist] "Stockist" in its messages; a Client-tier page never names Stockist: say "your courier"',
    ]);
});

it('fails a Client Glossary entry that names Stockist in anything a reader sees', function () {
    $run = launchChecks(linkedContent()->file('client-glossary/delivery.md', <<<'MD'
        ---
        term: Delivery
        audience_tier: Client
        where_you_see_it: Your Stockist deliveries list.
        other_names:
          - Inbound
        checked_on: 2026-10-24
        ---

        Stock your 3PL receives for you, as Stockist records it.
        MD))->against(linkedProject())->run();

    expect($run->failures())->toBe([
        'client-glossary/delivery.md:4 [links.stockist] "Stockist" in its where you see it; a Client-tier page never names Stockist: say "your courier"',
        'client-glossary/delivery.md:10 [links.stockist] "Stockist" in its words; a Client-tier page never names Stockist: say "your courier"',
    ]);
});

it('fails Stockist on a staff page or Glossary Entry that isn\'t Joint-only, since FMM-only readers meet it (ADR-0006)', function () {
    $run = launchChecks(linkedContent()
        ->article('receive-a-delivery', ['summary' => 'How to receive a delivery in Stockist, in one sentence.', 'related_articles' => ['book-in-a-delivery', 'how-deliveries-work']])
        ->glossaryEntry('location', [], 'A place in Stockist.'))
        ->against(linkedProject())->run();

    expect($run->failures('links'))->toContain(
        'articles/receive-a-delivery.md:3 [links.stockist] "Stockist" in its summary; a reader at an FMM-only Operator never meets Stockist: put it in a Joint-only Article (joint_only: true)',
    )->and(array_values(array_filter($run->failures('links'), fn (string $line): bool => str_starts_with($line, 'glossary/location.md'))))->toHaveCount(1);
});

it('fails a link from a page that isn\'t Joint-only to a Joint-only Article', function () {
    $run = launchChecks(linkedContent()
        ->article('stockist-deliveries', ['joint_only' => true, 'related_articles' => ['receive-a-delivery', 'book-in-a-delivery']])
        ->article('receive-a-delivery', ['related_articles' => ['book-in-a-delivery', 'how-deliveries-work']], "See [the other side](article:stockist-deliveries).\n"))
        ->against(linkedProject())->run();

    expect($run->failures('links'))->toContain(
        'articles/receive-a-delivery.md:20 [links.resolves] link "article:stockist-deliveries": "stockist-deliveries" is Joint-only, so only a Joint-only Article links to it: a reader at an FMM-only Operator never meets Stockist',
    );
});
