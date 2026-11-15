<?php

use App\Records\Records;
use Carbon\CarbonImmutable;
use Tests\Support\ReaderPage;

/*
| Glossary answers (spec #30, user stories 51 and 52; "Search"): a reader types their own word
| for a thing and is told Stockist's word. A term or one of its Other Names puts its Glossary
| Entry on top as a short answer, with the Articles that use the term below. Other Names load
| as one-way synonyms (Other Name to term) on each rebuild.
*/

function searchFor(string $words): ReaderPage
{
    return ReaderPage::of(test()->get('/search?'.http_build_query(['q' => $words]))->assertOk());
}

it('answers a reader\'s own word with the term\'s entry on top, then the Articles that use the term', function () {
    content()
        ->glossaryEntry('location', ['other_names' => ['bin', 'slot']], "A **Location** is one place in the warehouse where stock is kept.\n")
        ->article('move-stock', ['title' => 'Move stock', 'summary' => 'How to move stock from one place to another.'],
            "Scan the [location](term:location) you are moving the stock to.\n")
        ->article('count-stock', ['title' => 'Count stock', 'summary' => 'How to count what is on the shelf.'])
        ->publish();

    $page = searchFor('bin');

    // The answer comes first in the page, above the results.
    $inOrder = $page->texts('.glossary-answers, .results');
    expect($inOrder)->toHaveCount(2)
        ->and($inOrder[0])->toContain('In First Mile Manager this is a Location.')
        ->and(whereResultsOpen($this, $page, '.glossary-answer .answer-term a'))->toBe([['Location', '/glossary/location']])
        ->and($page->text('.glossary-answer .answer-also'))->toBe('Also called bin. In First Mile Manager this is a Location.')
        ->and($page->text('.glossary-answer .answer-body'))->toBe('A Location is one place in the warehouse where stock is kept.')
        ->and($page->texts('.results .result-title'))->toBe(['Move stock']);
});

it('shows both entries for a word shared by two terms', function () {
    content()
        ->glossaryEntry('location', ['other_names' => ['bay', 'bin']], "A Location is one place in the warehouse where stock is kept.\n")
        ->glossaryEntry('dock-door', ['other_names' => ['bay', 'loading bay']], "A Dock Door is where lorries are loaded and unloaded.\n")
        ->glossaryEntry('pallet', [], "A Pallet is a flat base that stock is stacked on.\n")
        ->publish();

    $page = searchFor('bay');

    expect($page->texts('.glossary-answer .answer-term'))->toEqualCanonicalizing(['Location', 'Dock Door'])
        ->and($page->texts('.glossary-answer .answer-also'))->toEqualCanonicalizing([
            'Also called bay. In First Mile Manager this is a Location.',
            'Also called bay. In First Mile Manager this is a Dock Door.',
        ]);
});

it('answers the term itself, without an "Also called" line', function (string $typed) {
    content()
        ->glossaryEntry('location', ['other_names' => ['bin']], "A Location is one place in the warehouse where stock is kept.\n")
        ->publish();

    $page = searchFor($typed);

    expect(whereResultsOpen($this, $page, '.glossary-answer .answer-term a'))->toBe([['Location', '/glossary/location']])
        ->and($page->has('.glossary-answer .answer-also'))->toBeFalse();
})->with([
    'as written' => ['Location'],
    'with a typo' => ['locaton'],
    'asked as a question' => ['What is a location?'],
]);

it('answers either name of a term written with its short form', function (string $typed) {
    content()
        ->glossaryEntry('advanced-shipping-notice-asn', ['term' => 'Advanced Shipping Notice (ASN)', 'other_names' => ['delivery note']])
        ->publish();

    expect(searchFor($typed)->texts('.glossary-answer .answer-term'))->toBe(['Advanced Shipping Notice (ASN)']);
})->with(['ASN', 'advanced shipping notice', 'delivery note']);

it('shows no answer when the words are more than a term', function () {
    content()
        ->glossaryEntry('stock', [], "Stock is the goods a client keeps in the warehouse.\n")
        ->article('move-stock', ['title' => 'Move stock'])
        ->publish();

    $page = searchFor('move stock');

    expect($page->has('.glossary-answer'))->toBeFalse()
        ->and($page->texts('.results .result-title'))->toBe(['Move stock']);
});

it('never answers with an entry reached only through another term\'s Other Name', function () {
    content()
        ->glossaryEntry('location', ['other_names' => ['bin']])
        // "location" is this entry's Other Name; "bin" maps to "location", but the reader didn't type it.
        ->glossaryEntry('pick-face', ['other_names' => ['location']])
        ->publish();

    expect(searchFor('bin')->texts('.glossary-answer .answer-term'))->toBe(['Location']);
});

it('maps an Other Name one way only: searching the term doesn\'t find the Other Name\'s unrelated uses', function () {
    content()
        ->glossaryEntry('location', ['other_names' => ['bin']], "A Location is one place in the warehouse where stock is kept.\n")
        ->article('put-stock-away', ['title' => 'Put stock away', 'summary' => 'How to put stock in its location.'])
        ->article('empty-the-waste', ['title' => 'Empty the waste', 'summary' => 'Take the waste bin out at the end of a shift.'])
        ->publish();

    expect(searchFor('location')->texts('.results .result-title'))->toBe(['Put stock away'])
        ->and(searchFor('bin')->texts('.results .result-title'))->toEqualCanonicalizing(['Put stock away', 'Empty the waste']);
});

it('answers from the Client Glossary in its own words, below the staff entry', function () {
    content()
        ->glossaryEntry('location', ['other_names' => ['bin']], "A Location is one place in the warehouse where stock is kept.\n")
        ->clientGlossaryEntry('location', ['other_names' => ['bin']], "A Location is where your stock sits in your 3PL's warehouse.\n")
        ->publish();

    $page = searchFor('bin');

    expect(whereResultsOpen($this, $page, '.glossary-answer .answer-term a'))->toBe([
        ['Location', '/glossary/location'],
        ['Location', '/client-glossary/location'],
    ])
        ->and($page->texts('.glossary-answer .answer-also'))->toBe([
            'Also called bin. In First Mile Manager this is a Location.',
            'Also called bin. Your courier calls this a Location.',
        ])
        ->and($page->texts('.glossary-answer .result-meta'))->toBe(['Glossary', 'Client Glossary For your clients']);
});

it('shows a Glossary answer instead of "No results" when no Article matches', function () {
    content()
        ->glossaryEntry('location', ['other_names' => ['bin']])
        ->publish();

    $page = searchFor('bin');

    expect($page->texts('.glossary-answer .answer-term'))->toBe(['Location'])
        ->and($page->has('.search-none'))->toBeFalse()
        ->and($page->has('.results'))->toBeFalse();
});

it('answers with a former name, which joins the entry\'s Other Names', function () {
    content()
        ->glossaryEntry('stock-container', ['other_names' => ['LPN'], 'former_names' => ['Licence Plate']])
        ->publish();

    expect(searchFor('licence plate')->texts('.glossary-answer .answer-also'))
        ->toBe(['Also called Licence Plate. In First Mile Manager this is a Stock Container.']);
});

it('records a Glossary answer among the results shown, and opening it as the first place', function () {
    config(['help.going_public' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00', 'Europe/London'));
    content()
        ->glossaryEntry('location', ['other_names' => ['bin']])
        ->article('move-stock', ['title' => 'Move stock'], "Scan the [location](term:location).\n")
        ->publish();

    $page = ReaderPage::of($this->withHeader('User-Agent', A_READER)->get('/search?q=bin'));
    $link = $page->links('.glossary-answer .answer-term a')[0][1];
    $this->withHeader('User-Agent', A_READER)->get($link)->assertRedirect('/glossary/location');

    $day = CarbonImmutable::parse('2026-11-03');
    expect(array_map(fn ($record) => $record->toArray(), app(Records::class)->searches($day, $day)))->toBe([[
        'words' => 'bin',
        'results_shown' => 2,
        'glossary_entry_shown' => true,
        'outcome' => 'Opened',
        'opened_result' => '/glossary/location',
        'opened_place' => 1,
        'day' => '2026-11-03',
        'where' => 'help centre',
    ]]);
});

it('gives the script its Glossary answers when it settles a Search', function () {
    content()
        ->glossaryEntry('location', ['other_names' => ['bin']])
        ->publish();

    $settled = $this->withHeaders(postedFromAHelpPage())->postJson('/searches', ['q' => 'bin']);

    expect($settled->json('glossary_answers'))->toHaveCount(1)
        ->and($settled->json('glossary_answers.0'))->toMatchArray([
            'place' => 1,
            'term' => 'Location',
            'also_called' => 'bin',
            'opens_at' => '/glossary/location',
        ])
        ->and($settled->json('results'))->toBe([]);
});
