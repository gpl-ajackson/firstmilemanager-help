<?php

use App\Records\Records;
use Carbon\CarbonImmutable;
use Tests\Support\ReaderBrowser;
use Tests\Support\SearchingReader;

/*
 * Article Feedback in a real browser (spec #30, user stories 62 to 67): Yes and No work the same
 * with script off, as plain form posts that show a page, and with script on, where the answer
 * appears in place at the end of the Article. Either way no cookie or storage is left behind.
 */

/** @return list<array<string, mixed>> */
function browserVotes(): array
{
    $day = CarbonImmutable::parse('2026-11-03');

    return array_map(fn ($vote): array => $vote->toArray(), app(Records::class)->votes($day, $day));
}

beforeEach(function () {
    config(['help.going_public' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00', 'Europe/London'));

    content()
        ->article('receive-at-the-dock', ['title' => 'Receive a delivery', 'related_articles' => ['count-stock', 'move-stock']])
        ->article('count-stock')
        ->article('move-stock')
        ->publish();
});

it('says thanks for a Yes', function (bool $script) {
    $visit = ReaderBrowser::visit($this, '/articles/receive-at-the-dock', script: $script, steps: [
        ['click', 'Yes'],
        ['see', 'Thanks'],
    ]);

    expect($visit->page()->text('.feedback'))->toBe('Thanks')
        ->and($visit->url)->toBe($script ? '/articles/receive-at-the-dock' : '/feedback/receive-at-the-dock')
        ->and(array_column(browserVotes(), 'answer'))->toBe(['Yes'])
        ->and($visit->cookies)->toBe(0);
    if ($script) {
        expect($visit->storage)->toBe(['local' => 0, 'session' => 0])
            ->and($visit->page()->text('h1'))->toBe('Receive a delivery');
    }
})->with(['script off' => false, 'script on' => true]);

it('asks what was wrong after a No, then records the reason and comment', function (bool $script) {
    $visit = ReaderBrowser::visit($this, '/articles/receive-at-the-dock', script: $script, steps: [
        ['click', 'No'],
        ['see', 'What was wrong?'],
        ['choose', 'It was hard to follow'],
        ['fill', 'Anything to add?', 'Step 3 lost me.'],
        ['click', 'Send'],
        ['see', 'Thanks for telling us'],
    ]);

    expect($visit->page()->text('.feedback-title'))->toBe('Thanks for telling us')
        ->and($visit->page()->links('.feedback-next .linklist a'))->toBe([
            ['Count stock How-to', '/articles/count-stock'],
            ['Move stock How-to', '/articles/move-stock'],
        ])
        ->and($visit->page()->has('.feedback-next form[role=search]'))->toBeTrue()
        ->and($visit->url)->toBe($script ? '/articles/receive-at-the-dock' : '/feedback/receive-at-the-dock/reason')
        ->and(browserVotes())->toBe([[
            'article' => 'receive-at-the-dock',
            'answer' => 'No',
            'reason' => 'It was hard to follow',
            'comment' => 'Step 3 lost me.',
            'checked_on' => '2026-10-24',
            'day' => '2026-11-03',
            'where' => 'help centre',
        ]])
        ->and($visit->cookies)->toBe(0);
})->with(['script off' => false, 'script on' => true]);

it('refuses a long comment in place, keeping it to shorten', function () {
    $visit = ReaderBrowser::visit($this, '/articles/receive-at-the-dock', script: true, steps: [
        ['click', 'No'],
        ['see', 'What was wrong?'],
        ['fill', 'Anything to add?', str_repeat('a', 501)],
        ['click', 'Send'],
        ['see', 'Your comment is 501 characters long. Please shorten it to 500 characters or fewer and send it again.'],
        ['fill', 'Anything to add?', str_repeat('a', 500)],
        ['click', 'Send'],
        ['see', 'Thanks for telling us'],
    ]);

    expect($visit->url)->toBe('/articles/receive-at-the-dock')
        ->and(mb_strlen((string) browserVotes()[0]['comment']))->toBe(500);
});

it('counts one vote when the answer is lost on the way back and the script posts the form plainly', function () {
    // The server records the vote, but the connection drops before its answer reaches the
    // page, so the script falls back to posting the same form as plain HTML.
    $visit = ReaderBrowser::visit($this, '/articles/receive-at-the-dock', script: true, lose: ['POST /feedback/receive-at-the-dock'], steps: [
        ['click', 'No'],
        ['see', 'What was wrong?'],
        ['choose', 'It was hard to follow'],
        ['click', 'Send'],
        ['see', 'Thanks for telling us'],
    ]);

    expect($visit->requests)->toContain('POST /feedback/receive-at-the-dock')
        ->and(array_count_values($visit->requests)['POST /feedback/receive-at-the-dock'])->toBe(2)
        ->and(browserVotes())->toBe([[
            'article' => 'receive-at-the-dock',
            'answer' => 'No',
            'reason' => 'It was hard to follow',
            'comment' => null,
            'checked_on' => '2026-10-24',
            'day' => '2026-11-03',
            'where' => 'help centre',
        ]])
        ->and($visit->cookies)->toBe(0)
        ->and($visit->storage)->toBe(['local' => 0, 'session' => 0]);
});

it('gives the search box under "Try next" suggestions, and adds no id to the Article page', function () {
    $visit = SearchingReader::visit($this, '/articles/receive-at-the-dock', [
        ['click', 'No'],
        ['see', 'What was wrong?'],
        ['type', 'count'],
        ['idle', 200],
    ]);

    // An Article page's only ids are its Section Names and the layout's main (ADR-0001), so a
    // link to a Section Name can never land on anything else.
    preg_match_all('/\sid="([^"]*)"/', $visit->html, $ids);
    expect($visit->page()->texts('.feedback .suggestions a .suggestion-title'))->toBe(['Count stock'])
        ->and($visit->url)->toBe('/articles/receive-at-the-dock')
        ->and($ids[1])->toBe(['main']);
});
