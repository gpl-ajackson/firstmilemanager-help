<?php

use Illuminate\Console\Command;

/*
| Glossary Entry header data (spec #30, "Content folder"): the term exactly as GLOSSARY.md names
| it, Audience Tier, Other Names, where you'll see it, a status's values with their meanings,
| former names and the date last checked. Checked on every rebuild, so a mistake fails the
| change and nothing is published.
*/

it('fails the rebuild when a Glossary Entry\'s header data is written wrongly', function (array $header, string $field, string $says) {
    $rebuild = content()
        ->glossaryEntry('zone')
        ->glossaryEntry('stock-container', $header)
        ->rebuild();

    expect($rebuild['exitCode'])->toBe(Command::FAILURE)
        ->and($rebuild['output'])->toContain("glossary/stock-container.md: {$field}: {$says}");
})->with([
    'no term' => [['term' => null], 'term', 'is missing'],
    'no where you\'ll see it' => [['where_you_see_it' => null], 'where_you_see_it', 'is missing'],
    'no date last checked' => [['checked_on' => null], 'checked_on', 'is missing'],
    'a date that is not a date' => [['checked_on' => '2026-02-30'], 'checked_on', '"2026-02-30" must be a date written YYYY-MM-DD'],
    'an unknown field' => [['see_also' => ['zone']], 'see_also', 'is not a Glossary Entry header data field'],
    'an Article\'s field' => [['status' => 'Published'], 'status', 'is not a Glossary Entry header data field'],
    'a file name that does not follow the term' => [['term' => 'Container'], 'term', 'the file for "Container" must be named container.md, so its address follows the term\'s name'],
    'the client tier in the staff folder' => [['audience_tier' => 'Client'], 'audience_tier', '"Client" entries belong in the client-glossary folder'],
    'an unknown tier' => [['audience_tier' => 'Everyone'], 'audience_tier', '"Everyone" must be one of: Staff, Client'],
    'other names that are not a list' => [['other_names' => 'pallet'], 'other_names', 'must be a list, one "- " line each'],
    'an other name that is the term' => [['other_names' => ['stock container']], 'other_names', '"stock container" is the term itself'],
    'an other name listed twice' => [['other_names' => ['pallet', 'pallet']], 'other_names', '"pallet" is listed twice'],
    'status values without meanings' => [['status_values' => ['Active', 'Inactive']], 'status_values', 'must be a list of the status\'s values, each "- value: <value>" with "meaning: <what it means>"'],
    'a status value with an unknown key' => [['status_values' => [['value' => 'Active', 'meaning' => 'In use.', 'colour' => 'green'], ['value' => 'Inactive', 'meaning' => 'Not in use.']]], 'status_values', 'must be a list of the status\'s values'],
    'a status value listed twice' => [['status_values' => [['value' => 'Active', 'meaning' => 'In use.'], ['value' => 'Active', 'meaning' => 'Also in use.']]], 'status_values', '"Active" is listed twice'],
    'a status with one value' => [['status_values' => [['value' => 'Active', 'meaning' => 'In use.']]], 'status_values', 'a status has at least two values'],
    'a former name that is the term' => [['former_names' => ['Stock-Container']], 'former_names', '"Stock-Container" is the term\'s own name'],
    'a former name that is another entry\'s term' => [['former_names' => ['Zone']], 'former_names', '"/glossary/zone" is in use by glossary/zone.md, and an address in use never redirects'],
]);

it('fails the rebuild when a Glossary Entry has no sentences', function () {
    $rebuild = content()->glossaryEntry('zone', [], '')->rebuild();

    expect($rebuild['exitCode'])->toBe(Command::FAILURE)
        ->and($rebuild['output'])->toContain('glossary/zone.md: body: the entry has no text after its header data');
});

it('fails the rebuild when a term\'s address would contain "stockist"', function () {
    $rebuild = content()->glossaryEntry('stockist-release', ['term' => 'Stockist Release'])->rebuild();

    expect($rebuild['output'])->toContain('glossary/stockist-release.md: term: "Stockist Release" would give an address containing "stockist"');
});

it('fails the rebuild when two entries of a tier claim the same former name', function () {
    $rebuild = content()
        ->glossaryEntry('stock-container', ['former_names' => ['Container']])
        ->glossaryEntry('pick-container', ['former_names' => ['Container']])
        ->clientGlossaryEntry('stock-container', ['former_names' => ['Container']])
        ->rebuild();

    expect($rebuild['exitCode'])->toBe(Command::FAILURE)
        ->and($rebuild['output'])->toContain('glossary/stock-container.md: former_names: "/glossary/container" already redirects to /glossary/pick-container (glossary/pick-container.md)')
        ->and($rebuild['output'])->not->toContain('client-glossary/stock-container.md: former_names');
});

it('fails the rebuild when a file in a glossary folder is not Markdown or has no header data', function () {
    $rebuild = content()
        ->file('client-glossary/on-hold.txt', "On Hold\n")
        ->file('glossary/zone.md', "A Zone is a part of a warehouse.\n")
        ->rebuild();

    expect($rebuild['exitCode'])->toBe(Command::FAILURE)
        ->and($rebuild['output'])->toContain('client-glossary/on-hold.txt: file: only Markdown files ending ".md" belong in the client-glossary folder')
        ->and($rebuild['output'])->toContain('glossary/zone.md: header data: the file must start with header data between two "---" lines');
});

it('changes nothing when a Glossary Entry is wrong', function () {
    content()->glossaryEntry('zone')->publish();

    content()->glossaryEntry('zone', ['term' => null])->rebuild();

    $this->get('/glossary/zone')->assertOk();
});

it('says how many Glossary Entries it published', function () {
    $rebuild = content()
        ->glossaryEntry('zone')
        ->glossaryEntry('aisle')
        ->clientGlossaryEntry('on-hold')
        ->rebuild();

    expect($rebuild['exitCode'])->toBe(Command::SUCCESS)
        ->and($rebuild['output'])->toContain('Rebuilt 3 Glossary Entries: 2 in the Glossary, 1 in the Client Glossary.');
});
