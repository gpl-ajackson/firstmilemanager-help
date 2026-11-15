<?php

use Illuminate\Console\Command;
use Tests\Support\FixtureContent;
use Tests\Support\FixtureMainProject;
use Tests\Support\LaunchCheckRun;

/*
 * Launch Check 5, Words (spec #30, seam 2): no GLOSSARY.md avoid word and no word the Style
 * Guide bans in an Article or Glossary Entry (whole words, any capitals, plurals), except Other
 * Names, quoted Stockist messages, a use marked in quotation marks as naming the wrong word,
 * and the Content Owner's Ignored Words.
 */

function wordsProject(): FixtureMainProject
{
    return mainProject()->glossary([
        'Location' => ['A place stock is kept.', ['Bin', 'slot', 'area', 'storage unit']],
        'Zone' => ['A part of a warehouse.', ['category', 'section']],
        'Rack' => ['A structure of shelves.', ['bay']],
        'Dock Bay' => ['Where a lorry is unloaded.', ['door']],
        'Stock Container' => ['A container that holds stock.', ['tote', 'Carrier (that is the delivery company)']],
        'Carrier' => 'The company that takes parcels away.',
    ]);
}

/** @param  list<string|array{word: string, say_instead?: string}>  $banned */
function wordsRun(FixtureContent $content, array $banned = ['please', 'just', 'e.g.', ['word' => 'click', 'say_instead' => 'select'], 'despatch']): LaunchCheckRun
{
    return launchChecks($content)->against(wordsProject())->styleGuide($banned)->run();
}

/** @return list<string> */
function wordFailures(LaunchCheckRun $run): array
{
    return array_values(array_filter($run->failures(), fn (string $line): bool => str_contains($line, '[words.')));
}

/** @return list<string> */
function wordReports(LaunchCheckRun $run): array
{
    return array_values(array_filter($run->reports(), fn (string $line): bool => str_contains($line, '[words.')));
}

/** A content folder whose Articles pass check 1's lists. */
function wordsContent(): FixtureContent
{
    return content()->productAreas(['Inbound' => 'inbound.cluster.title'])->articleOwners(['gpl-ajackson']);
}

function anArticle(string $body, array $header = []): FixtureContent
{
    return wordsContent()->article('put-stock-away', $header, "## In short\n\n{$body}\n");
}

it('passes help written in GLOSSARY.md\'s words', function () {
    $run = wordsRun(anArticle('Scan the location, then put the stock container on the rack.'));

    expect($run->exitCode)->toBe(Command::SUCCESS)
        ->and($run->failures())->toBe([])
        ->and(wordReports($run))->toBe([]);
});

it('fails an avoid word in body text, naming the file, the line and the term to use', function () {
    $content = anArticle("Find the shelf.\nPut the stock in the bin.");
    $run = wordsRun($content);

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe([
            'articles/put-stock-away.md:'.$content->lineOf('articles/put-stock-away.md', 'Put the stock').' [words.avoid] "bin" is a word GLOSSARY.md says to avoid: say "Location" (to name it as the wrong word, put it in quotation marks)',
        ]);
});

it('catches plurals and capitals', function (string $sentence, string $found, string $term) {
    $run = wordsRun(anArticle($sentence));

    expect(wordFailures($run))->toHaveCount(1)
        ->and(wordFailures($run)[0])->toContain("[words.avoid] \"{$found}\" is a word GLOSSARY.md says to avoid: say \"{$term}\"");
})->with([
    'a plural' => ['Count the bins.', 'bins', 'Location'],
    'capitals' => ['BIN numbers are printed.', 'BIN', 'Location'],
    'a plural in -ies' => ['Pick from both categories.', 'categories', 'Zone'],
    'a plural in -es' => ['Count the boxes in both bays.', 'bays', 'Rack'],
    'a phrase across a line break' => ["Each storage\nunit has a code.", 'storage unit', 'Location'],
    'a phrase in plural' => ['Storage units are labelled.', 'Storage units', 'Location'],
]);

it('looks only for whole words', function () {
    $run = wordsRun(anArticle('Combine the cabinets with the bayonet fittings, slotted in by the zone\'s binman.'));

    expect(wordFailures($run))->toBe([]);
});

it('passes an avoid word where it names the wrong word, in quotation marks', function (string $sentence) {
    $run = wordsRun(anArticle($sentence));

    expect(wordFailures($run))->toBe([]);
})->with([
    'straight quotes' => ['Stockist calls a "bin" a location.'],
    'curly quotes' => ['Stockist calls a “bin” a location.'],
    'single curly quotes' => ['Stockist calls a ‘bin’ a location.'],
    'a plural in quotes' => ['What you may call "Bins" are locations.'],
]);

it('still fails an avoid word inside a longer quotation', function () {
    $run = wordsRun(anArticle('Select "Move to bin" to move it.'));

    expect(wordFailures($run))->toHaveCount(1);
});

it('passes a quoted Stockist message, in a quote block or in the messages header', function () {
    $content = wordsContent()->article('stock-wont-go-into-this-location', [
        'type' => 'Troubleshooting',
        'messages' => ['This bin is full.'],
    ], "## In short\n\nThe location is full.\n\n> The bin [location] is full.\n");

    expect(wordFailures(wordsRun($content)))->toBe([]);
});

it('passes avoid words on the Content Owner\'s Ignored Words, in any capitals', function () {
    $run = wordsRun(anArticle('Clear the Area by the door.')->ignoredWords(['area', 'Door']));

    expect(wordFailures($run))->toBe([])
        ->and(wordReports($run))->toBe([]);
});

it('reports an Ignored Word that GLOSSARY.md no longer lists to avoid', function () {
    $content = anArticle('Scan the location.')->ignoredWords(['area', 'site']);
    $run = wordsRun($content);

    expect($run->exitCode)->toBe(Command::SUCCESS)
        ->and(wordReports($run))->toBe([
            'ignored-words.yaml:2 [words.ignored] "site" is not an avoid word in GLOSSARY.md: take it off the Ignored Words',
        ]);
});

it('passes a GLOSSARY.md term that another term lists to avoid, and avoid words inside a longer term', function () {
    $run = wordsRun(anArticle('Book a carrier for the dock bay. Carriers collect from Dock Bays.'));

    expect(wordFailures($run))->toBe([]);
});

it('passes what a reader scans or types, in the mono face', function () {
    $run = wordsRun(anArticle('Enter `BIN` in the search box, then scan `slot 4`.'));

    expect(wordFailures($run))->toBe([]);
});

it('fails a word the Style Guide bans, with what to say instead where it says', function () {
    $content = anArticle("Just scan the label.\n\nClick **Save**, e.g. after a count.");
    $run = wordsRun($content);
    $file = 'articles/put-stock-away.md';

    expect(wordFailures($run))->toBe([
        "{$file}:{$content->lineOf($file, 'Just scan')} [words.banned] \"Just\" is a word the Style Guide bans",
        "{$file}:{$content->lineOf($file, 'Click')} [words.banned] \"Click\" is a word the Style Guide bans: say \"select\"",
        "{$file}:{$content->lineOf($file, 'Click')} [words.banned] \"e.g.\" is a word the Style Guide bans",
    ]);
});

it('fails avoid and banned words in an Article\'s title, summary and headings', function () {
    $content = wordsContent()->article('fill-a-bin', [
        'title' => 'Fill a bin',
        'summary' => 'Please fill each location in turn.',
    ], "## In short\n\nFill each location.\n\n## Empty slots {#empty-slots}\n\nCount them.\n");
    $run = wordsRun($content);
    $file = 'articles/fill-a-bin.md';

    expect(wordFailures($run))->toBe([
        "{$file}:2 [words.avoid] \"bin\" is a word GLOSSARY.md says to avoid: say \"Location\" (to name it as the wrong word, put it in quotation marks)",
        "{$file}:3 [words.banned] \"Please\" is a word the Style Guide bans",
        "{$file}:{$content->lineOf($file, '## Empty slots')} [words.avoid] \"slots\" is a word GLOSSARY.md says to avoid: say \"Location\" (to name it as the wrong word, put it in quotation marks)",
    ]);
});

it('fails avoid words in a diagram\'s words', function () {
    $content = anArticle("Containers move like this.\n\n```mermaid\nflowchart LR\n  accDescr: A tote moves to a location.\n  A[Dock] --> B[Tote]\n```");
    $run = wordsRun($content);

    expect(wordFailures($run))->toBe([
        'articles/put-stock-away.md:'.$content->lineOf('articles/put-stock-away.md', 'accDescr').' [words.avoid] "tote" is a word GLOSSARY.md says to avoid: say "Stock Container" (to name it as the wrong word, put it in quotation marks)',
        'articles/put-stock-away.md:'.$content->lineOf('articles/put-stock-away.md', 'B[Tote]').' [words.avoid] "Tote" is a word GLOSSARY.md says to avoid: say "Stock Container" (to name it as the wrong word, put it in quotation marks)',
    ]);
});

it('checks Glossary Entries but not their Other Names, former names or status values', function () {
    $content = content()
        ->glossaryEntry('location', [
            'other_names' => ['bin', 'slot'],
            'former_names' => ['Storage Unit'],
            'where_you_see_it' => 'On every bin label.',
        ], "A place stock is kept, which you may call a \"bin\".\n")
        ->clientGlossaryEntry('zone', [
            'status_values' => [
                ['value' => 'Section', 'meaning' => 'A category of stock.'],
                ['value' => 'Open', 'meaning' => 'You can send stock.'],
            ],
        ], "A part of your 3PL's warehouse, like a section of a shop.\n");
    $run = wordsRun($content);

    expect(wordFailures($run))->toBe([
        'glossary/location.md:'.$content->lineOf('glossary/location.md', 'where_you_see_it').' [words.avoid] "bin" is a word GLOSSARY.md says to avoid: say "Location" (to name it as the wrong word, put it in quotation marks)',
        'client-glossary/zone.md:'.$content->lineOf('client-glossary/zone.md', 'A category').' [words.avoid] "category" is a word GLOSSARY.md says to avoid: say "Zone" (to name it as the wrong word, put it in quotation marks)',
        'client-glossary/zone.md:'.$content->lineOf('client-glossary/zone.md', 'like a section').' [words.avoid] "section" is a word GLOSSARY.md says to avoid: say "Zone" (to name it as the wrong word, put it in quotation marks)',
    ]);
});

it('checks Draft Articles too, so a word is fixed before it is published', function () {
    expect(wordFailures(wordsRun(anArticle('Put it in the bin.', ['status' => 'Draft']))))->toHaveCount(1);
});

it('checks only GLOSSARY.md\'s avoid words while there is no Style Guide, and says so', function () {
    $run = launchChecks(anArticle('Just put it in the bin.'))->against(wordsProject())->run();

    expect(wordFailures($run))->toHaveCount(1)
        ->and(wordFailures($run)[0])->toContain('[words.avoid] "bin"')
        ->and($run->output)->toContain('Not run: Words check, the Style Guide\'s banned words: there is no Style Guide at ');
});

it('fails a Style Guide whose banned words it can\'t read', function () {
    $run = launchChecks(anArticle('Scan it.'))->against(wordsProject())
        ->styleGuide(null, "---\nbanned_words: please\n---\n\n# Style Guide\n")->run();

    expect(wordFailures($run))->toHaveCount(1)
        ->and(wordFailures($run)[0])->toEndWith('[words.style-guide] banned_words must be a list, each a word or phrase, or "word:" with "say_instead:"');
});

it('fails Ignored Words it can\'t read', function () {
    $run = wordsRun(anArticle('Scan it.')->file('ignored-words.yaml', "area: yes\n"));

    expect(wordFailures($run))->toBe([
        'ignored-words.yaml:1 [words.ignored-format] must be a list of words, one "- " line each',
    ]);
});

it('fails a word Stockist\'s GLOSSARY.md says to avoid for a term both apps share (fmm#56)', function () {
    $stockist = mainProject()->glossary(['Location' => ['A place stock is kept.', ['pigeonhole']]]);
    $content = anArticle('Put the stock in the pigeonhole.');
    $run = launchChecks($content)->against(wordsProject())->stockist($stockist)->styleGuide([])->run();

    expect(wordFailures($run))->toBe([
        'articles/put-stock-away.md:'.$content->lineOf('articles/put-stock-away.md', 'Put the stock').' [words.avoid] "pigeonhole" is a word GLOSSARY.md says to avoid: say "Location" (to name it as the wrong word, put it in quotation marks)',
    ]);
});

it('doesn\'t fail Stockist\'s avoid words for a term First Mile Manager doesn\'t share', function () {
    $stockist = mainProject()->glossary(['Putaway' => ['Moving received stock to a Location.', ['pigeonhole']]]);
    $run = launchChecks(anArticle('Put the stock in the pigeonhole.'))->against(wordsProject())->stockist($stockist)->styleGuide([])->run();

    expect(wordFailures($run))->toBe([]);
});

it('doesn\'t fail a Stockist avoid word that First Mile Manager\'s own names are made of', function () {
    // Stockist avoids "manager" for its Admin; First Mile Manager's roles and name use it.
    $project = mainProject()->glossary([
        'Admin' => ['The owner\'s Staff Role.', ['superuser']],
        'Transport Manager' => 'A Staff Role that keeps vehicles.',
    ]);
    $stockist = mainProject()->glossary(['Admin' => ['The owner\'s Staff Role.', ['Superuser', 'manager']]]);
    $run = launchChecks(anArticle('Ask your Admin or Transport Manager in First Mile Manager, not the superuser.'))
        ->against($project)->stockist($stockist)->styleGuide([])->run();

    expect(wordFailures($run))->toHaveCount(1)
        ->and(wordFailures($run)[0])->toContain('"superuser" is a word GLOSSARY.md says to avoid: say "Admin"');
});
