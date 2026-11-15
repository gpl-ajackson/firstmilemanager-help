<?php

use Illuminate\Console\Command;

/*
| The header data #36 adds (spec #30, "Content folder"): next review date, Team Roles, Source
| References, related articles, and by type the place in the path (Getting-started) or the
| messages (Troubleshooting). Each rule is checked by the rebuild, naming the file and field.
*/

it('publishes an Article of every type and both tiers with full header data', function (array $header) {
    content()->article('the-article', $header)->publish();

    $this->get('/articles/the-article')->assertOk();
})->with([
    'a staff How-to' => [['type' => 'How-to']],
    'a staff Getting-started Article' => [['type' => 'Getting-started']],
    'a staff Concept' => [['type' => 'Concept']],
    'a staff Reference' => [['type' => 'Reference']],
    'a staff Troubleshooting Article' => [['type' => 'Troubleshooting']],
    'a staff FAQ' => [['type' => 'FAQ']],
    'a Client-tier How-to' => [['type' => 'How-to', 'audience_tier' => 'Client']],
    'a Client-tier Concept' => [['type' => 'Concept', 'audience_tier' => 'Client']],
    'a Getting-started Article without related articles' => [['type' => 'Getting-started', 'related_articles' => null]],
    'a review date exactly six months on' => [['checked_on' => '2026-08-31', 'next_review_on' => '2027-02-28']],
    'every form of Source Reference' => [['source_references' => ['.junie/plans/Clients/2026-05-01-client-holds.md', 'ADR-0019', 'fmm#128']]],
]);

it('fails the rebuild naming the file and field when the new header data is wrong', function (array $header, string $field, string $says) {
    $rebuild = content()
        ->article('receive-at-the-dock')
        ->article('move-a-container', $header)
        ->rebuild();

    expect($rebuild['exitCode'])->toBe(Command::FAILURE)
        ->and($rebuild['output'])->toContain("articles/move-a-container.md: {$field}: {$says}")
        ->and($rebuild['output'])->not->toContain('articles/receive-at-the-dock.md');
})->with([
    'no next review date' => [['next_review_on' => null], 'next_review_on', 'is missing'],
    'a next review date that is not a date' => [['next_review_on' => 'spring'], 'next_review_on', '"spring" must be a date written YYYY-MM-DD'],
    'a next review date before Checked On' => [['checked_on' => '2026-10-24', 'next_review_on' => '2026-10-01'], 'next_review_on', 'must be after checked_on (2026-10-24)'],
    'a next review date over six months on' => [['checked_on' => '2026-10-24', 'next_review_on' => '2027-04-25'], 'next_review_on', 'must be six months at most after checked_on (2027-04-24 at the latest)'],
    'a staff Article with no Team Roles' => [['team_roles' => null], 'team_roles', 'is missing (Staff Articles name the Team Roles they are for)'],
    'Team Roles as one piece of text' => [['team_roles' => 'Admin'], 'team_roles', 'must be a list'],
    'an empty Team Role' => [['team_roles' => ['Admin', '']], 'team_roles', 'must be a list of names'],
    'a Team Role listed twice' => [['team_roles' => ['Admin', 'Admin']], 'team_roles', '"Admin" is listed twice'],
    'no Source References' => [['source_references' => null], 'source_references', 'is missing'],
    'an empty list of Source References' => [['source_references' => []], 'source_references', 'is missing'],
    'a Source Reference in no known form' => [['source_references' => ['the client holds plan']], 'source_references', '"the client holds plan" must be a plan or spec path ending ".md", ADR-nnnn or fmm#n'],
    'no related articles' => [['related_articles' => null], 'related_articles', 'is missing'],
    'one related article' => [['related_articles' => ['put-stock-away']], 'related_articles', 'must list two to five Article Names (it has 1)'],
    'six related articles' => [['related_articles' => ['a', 'b', 'c', 'd', 'e', 'f']], 'related_articles', 'must list two to five Article Names (it has 6)'],
    'a related article that is not an Article Name' => [['related_articles' => ['put-stock-away', 'Put Stock Away']], 'related_articles', '"Put Stock Away" must be an Article Name'],
    'a related article listed twice' => [['related_articles' => ['put-stock-away', 'put-stock-away']], 'related_articles', '"put-stock-away" is listed twice'],
    'a Getting-started Article with no place in the path' => [['type' => 'Getting-started', 'path_position' => null], 'path_position', 'is missing (every Getting-started Article has its place in the path)'],
    'a place in the path that is not a whole number' => [['type' => 'Getting-started', 'path_position' => 'third'], 'path_position', 'must be a whole number from 1'],
    'a place in the path of 0' => [['type' => 'Getting-started', 'path_position' => 0], 'path_position', 'must be a whole number from 1'],
    'a place in the path on a How-to' => [['type' => 'How-to', 'path_position' => 3], 'path_position', 'belongs only on Getting-started Articles'],
    'a Troubleshooting Article with no messages' => [['type' => 'Troubleshooting', 'messages' => null], 'messages', 'is missing (a Troubleshooting Article lists every message it explains)'],
    'a message that is not text' => [['type' => 'Troubleshooting', 'messages' => [['client' => 'Lumen']]], 'messages', 'must be a list of messages, each written word for word'],
    'a Placeholder left open' => [['type' => 'Troubleshooting', 'messages' => ['[client is on hold']], 'messages', '"[client is on hold" has a Placeholder without its closing "]"'],
    'an empty Placeholder' => [['type' => 'Troubleshooting', 'messages' => ['[] is on hold']], 'messages', '"[] is on hold" has an empty Placeholder'],
    'messages on a How-to' => [['type' => 'How-to', 'messages' => ['Location is quarantined']], 'messages', 'belong only on Troubleshooting Articles'],
]);

it('fails the rebuild when two published Getting-started Articles share a place in the path', function () {
    $rebuild = content()
        ->article('build-your-warehouse', ['type' => 'Getting-started', 'path_position' => 2])
        ->article('add-your-team', ['type' => 'Getting-started', 'path_position' => 2])
        ->rebuild();

    expect($rebuild['exitCode'])->toBe(Command::FAILURE)
        ->and($rebuild['output'])->toContain('articles/build-your-warehouse.md: path_position: 2 is also the place of add-your-team');
});

it('lets a Draft hold a place in the path a Published Article already has', function () {
    content()
        ->article('build-your-warehouse', ['type' => 'Getting-started', 'path_position' => 2])
        ->article('build-your-warehouse-again', ['type' => 'Getting-started', 'path_position' => 2, 'status' => 'Draft'])
        ->publish();

    $this->get('/articles/build-your-warehouse')->assertOk();
});

it('fails the rebuild when a Section Name is not written as one', function (string $heading, string $says) {
    $rebuild = content()
        ->article('move-a-container', [], "## In short\n\nMove it.\n\n{$heading}\n\nText.\n")
        ->rebuild();

    expect($rebuild['exitCode'])->toBe(Command::FAILURE)
        ->and($rebuild['output'])->toContain("articles/move-a-container.md: Section Name: {$says}");
})->with([
    'capitals' => ['## Too small {#Too-Small}', '"Too-Small" must be lower-case letters and numbers joined by single hyphens'],
    'not at the end of the heading' => ['## Too {#too-small} small', 'write it at the end of its heading, as "## Heading {#section-name}" ("Too {#too-small} small")'],
]);

it('fails the rebuild when two Sections share a Section Name', function () {
    $rebuild = content()
        ->article('move-a-container', [], "## Too small {#too-small}\n\nA.\n\n## Too full {#too-small}\n\nB.\n")
        ->rebuild();

    expect($rebuild['exitCode'])->toBe(Command::FAILURE)
        ->and($rebuild['output'])->toContain('articles/move-a-container.md: Section Name: "too-small" names two Sections');
});
