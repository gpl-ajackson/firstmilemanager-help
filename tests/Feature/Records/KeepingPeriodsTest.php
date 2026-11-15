<?php

use App\Records\Answer;
use App\Records\FeedbackReason;
use App\Records\Records;
use App\Records\SearchOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\ReaderPage;

/*
 * Keeping periods (spec #30, "Keeping"; user stories 100 and 101; rule 11): Search Records, votes
 * and Feedback Reasons are kept 13 months and feedback comments 6, or less if the Content Owner
 * deletes one. A daily job prunes. Monthly totals, which hold no words, are kept for good, so a
 * month can be compared with the same month a year earlier.
 */

/** Moves the clock to a time of day in the UK. */
function onUkTime(string $moment): void
{
    test()->travelTo(CarbonImmutable::parse($moment, Records::TIMEZONE));
}

/** Settles a Search as a reader does with the plain form. */
function readerSearches(string $words): void
{
    test()->withHeader('User-Agent', A_READER)->get('/search?q='.rawurlencode($words))->assertOk();
}

/** Votes on an Article as a reader does from its page, adding a reason and comment to a No. */
function readerVotes(string $article, string $answer, ?string $reason = null, ?string $comment = null): void
{
    $headers = ['User-Agent' => A_READER, 'Origin' => rtrim(url('/'), '/'), 'Sec-Fetch-Site' => 'same-origin'];
    $response = test()->withHeaders($headers)->post("/feedback/{$article}", ['answer' => $answer])->assertOk();
    if ($answer === 'no' && ($reason !== null || $comment !== null)) {
        preg_match('/name="vote" value="([^"]+)"/', (string) $response->getContent(), $vote);
        test()->withHeaders($headers)
            ->post("/feedback/{$article}/reason", ['vote' => $vote[1], 'reason' => $reason ?? '', 'comment' => $comment ?? ''])
            ->assertOk();
    }
}

/**
 * Runs the pruning job if the scheduler has it due now, as `schedule:run` does on the server, but
 * in this process, so it sees the test's database. Other jobs due at the same minute (the
 * "doesn't match" issue desk, which needs GitHub) are left to their own tests.
 */
function runPruningWhenDue(): void
{
    $due = collect(app(Schedule::class)->dueEvents(app()))
        ->map(fn ($event): string => Str::after((string) $event->command, "'artisan' "))
        ->filter(fn (string $command): bool => Str::startsWith($command, 'help:prune-records'));
    expect($due)->not->toBeEmpty();
    foreach ($due as $command) {
        test()->artisan($command)->assertSuccessful();
    }
}

/** @return list<string> */
function searchWordsKept(): array
{
    $records = app(Records::class)->searches(CarbonImmutable::parse('2000-01-01'), CarbonImmutable::parse('2100-01-01'));

    return array_map(fn ($record): string => $record->words, $records);
}

/** @return list<array{string, string, ?string, ?string}> day, answer, reason and comment of every vote kept */
function votesKept(): array
{
    $votes = app(Records::class)->votes(CarbonImmutable::parse('2000-01-01'), CarbonImmutable::parse('2100-01-01'));

    return array_map(fn ($vote): array => [$vote->day->toDateString(), $vote->answer->value, $vote->reason?->value, $vote->comment], $votes);
}

beforeEach(function () {
    config(['help.going_public' => true]);
    onUkTime('2026-11-02 09:00');

    content()
        ->article('receive-a-delivery', ['title' => 'Receive a delivery', 'checked_on' => '2026-10-24'])
        ->article('count-stock', ['title' => 'Count stock', 'checked_on' => '2026-10-24'])
        ->glossaryEntry('location', ['other_names' => ['bin']], "A **Location** is one place in the warehouse where stock is kept.\n")
        ->publish();
});

it('removes Search Records, votes and Feedback Reasons after 13 months and comments after 6, in the daily job', function () {
    onUkTime('2026-11-03 10:00');
    readerSearches('book in a delivery november third');
    readerVotes('receive-a-delivery', 'no', 'It was hard to follow', 'Written on the third of November.');

    onUkTime('2026-11-04 10:00');
    readerSearches('count stock november fourth');
    readerVotes('count-stock', 'no', 'Something else', 'Written on the fourth of November.');

    onUkTime('2027-06-03 10:00');
    readerVotes('receive-a-delivery', 'no', "It doesn't match what I see", 'Written on the third of June.');

    onUkTime('2027-06-04 10:00');
    readerVotes('receive-a-delivery', 'no', null, 'Written on the fourth of June.');
    readerVotes('count-stock', 'yes');

    onUkTime('2027-12-04 03:30');
    runPruningWhenDue();

    expect(searchWordsKept())->toBe(['count stock november fourth'])
        ->and(votesKept())->toBe([
            ['2026-11-04', 'No', 'Something else', null],
            ['2027-06-03', 'No', "It doesn't match what I see", null],
            ['2027-06-04', 'Yes', null, null],
            ['2027-06-04', 'No', null, 'Written on the fourth of June.'],
        ]);
});

it('keeps monthly totals for good, holding no search words or comments', function () {
    onUkTime('2026-11-03 10:00');
    readerSearches('nothing matches zqxj');
    readerSearches('bin');
    readerSearches('count stock');
    $link = ReaderPage::of($this->withHeader('User-Agent', A_READER)->get('/search?q=receive+a+delivery'))
        ->links('.results .result-title a')[0][1];
    $this->withHeader('User-Agent', A_READER)->get($link)->assertRedirect('/articles/receive-a-delivery');
    readerVotes('receive-a-delivery', 'yes');
    readerVotes('receive-a-delivery', 'no', 'It was hard to follow', 'The steps skip the dock door.');

    onUkTime('2026-11-30 18:00');
    readerVotes('count-stock', 'no', "It doesn't match what I see", 'There is no Count button.');
    readerVotes('count-stock', 'no');

    onUkTime('2026-12-01 09:00');
    readerSearches('december search');
    readerVotes('count-stock', 'yes');

    // Nights until December 2026's records are more than 13 months old.
    foreach (['2026-12-01', '2027-06-15', '2027-12-01', '2028-01-02'] as $day) {
        onUkTime("{$day} 03:30");
        runPruningWhenDue();
    }

    $november = app(Records::class)->monthlyTotals(CarbonImmutable::parse('2026-11-01'));
    expect(searchWordsKept())->toBe([])
        ->and(votesKept())->toBe([])
        ->and($november->searches())->toBe(4)
        ->and($november->searches(SearchOutcome::NoResults))->toBe(1)
        ->and($november->searches(SearchOutcome::Opened))->toBe(1)
        ->and($november->searches(SearchOutcome::NotOpened))->toBe(2)
        ->and($november->answeredAtTop())->toBe(1)
        ->and($november->votes())->toBe(4)
        ->and($november->votes(answer: Answer::Yes))->toBe(1)
        ->and($november->votes('count-stock', Answer::No))->toBe(2)
        ->and($november->votes('receive-a-delivery', Answer::No))->toBe(1)
        ->and($november->reasons())->toBe([
            FeedbackReason::DoesntMatch->value => 1,
            FeedbackReason::HardToFollow->value => 1,
        ])
        ->and(app(Records::class)->monthlyTotals(CarbonImmutable::parse('2026-12-01'))?->votes())->toBe(1);

    $everything = collect(Schema::getTableListing(schemaQualified: false))
        ->reject(fn (string $table): bool => in_array($table, ['migrations', 'articles', 'glossary_entries', 'redirects'], true))
        ->map(fn (string $table): string => json_encode(DB::table($table)->get(), JSON_UNESCAPED_SLASHES) ?: '')
        ->implode("\n");
    foreach (['zqxj', 'count stock', 'receive a delivery', 'december search', 'dock door', 'Count button'] as $words) {
        expect($everything)->not->toContain($words);
    }
});

it('has no monthly totals for a month before anything was recorded, or one not yet over', function () {
    onUkTime('2026-11-03 10:00');
    readerSearches('count stock');

    onUkTime('2026-11-20 03:30');
    runPruningWhenDue();

    expect(app(Records::class)->monthlyTotals(CarbonImmutable::parse('2026-10-01')))->toBeNull()
        ->and(app(Records::class)->monthlyTotals(CarbonImmutable::parse('2026-11-01')))->toBeNull();

    onUkTime('2026-12-01 03:30');
    runPruningWhenDue();

    expect(app(Records::class)->monthlyTotals(CarbonImmutable::parse('2026-11-01'))?->searches())->toBe(1);
});

it('lets the Content Owner find a comment by its Article and day, and delete it sooner, keeping its vote', function () {
    onUkTime('2026-11-03 10:00');
    readerVotes('receive-a-delivery', 'no', 'Something else', 'Call me on 07700 900123 about this.');
    readerVotes('receive-a-delivery', 'no', 'It was hard to follow', 'The steps skip the dock door.');
    readerVotes('count-stock', 'no', null, 'Count stock comment.');

    onUkTime('2026-11-10 14:00');
    Artisan::call('help:delete-comment', ['article' => 'receive-a-delivery', '--day' => '2026-11-03']);
    $listing = Artisan::output();
    preg_match('/([0-9a-f-]{36})\s.*07700 900123/', $listing, $found);

    expect($listing)->toContain('Call me on 07700 900123 about this.')
        ->and($listing)->toContain('The steps skip the dock door.')
        ->and($listing)->not->toContain('Count stock comment.')
        ->and($found)->toHaveCount(2)
        ->and(votesKept())->toHaveCount(3);

    $this->artisan('help:delete-comment', ['article' => 'receive-a-delivery', '--vote' => $found[1]])
        ->expectsOutputToContain('Deleted the comment “Call me on 07700 900123 about this.”')
        ->assertSuccessful();

    expect(votesKept())->toEqualCanonicalizing([
        ['2026-11-03', 'No', null, 'Count stock comment.'],
        ['2026-11-03', 'No', 'Something else', null],
        ['2026-11-03', 'No', 'It was hard to follow', 'The steps skip the dock door.'],
    ]);
});

it('deletes nothing when the vote is not one on that Article with a comment', function () {
    onUkTime('2026-11-03 10:00');
    readerVotes('receive-a-delivery', 'no', null, 'Keep this comment.');
    Artisan::call('help:delete-comment', ['article' => 'receive-a-delivery']);
    preg_match('/[0-9a-f-]{36}/', Artisan::output(), $found);

    $this->artisan('help:delete-comment', ['article' => 'count-stock', '--vote' => $found[0]])
        ->expectsOutputToContain('No comment on count-stock has that reference')
        ->assertFailed();
    $this->artisan('help:delete-comment', ['article' => 'receive-a-delivery', '--vote' => 'not-a-reference'])
        ->assertFailed();

    expect(votesKept())->toBe([['2026-11-03', 'No', null, 'Keep this comment.']]);
});
