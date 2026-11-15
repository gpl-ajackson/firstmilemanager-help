<?php

use App\Records\Records;
use App\Records\SearchOutcome;
use App\Site\Http\SearchLimit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ReaderPage;
use Tests\Support\ScriptlessBrowser;
use Tests\TestCase;

/*
 * Search Records (spec #30, "Search"; user stories 96, 97; rule 11): every settled Search is
 * recorded as cleaned words, how many results were shown, whether a Glossary Entry was among
 * them, its outcome and the result opened with its place, the date and where it was made. Never
 * anything about who searched.
 */

/** @return list<array<string, mixed>> */
function searchRecordsOn(string $day): array
{
    $date = CarbonImmutable::parse($day);

    return array_map(fn ($record) => $record->toArray(), app(Records::class)->searches($date, $date));
}

/**
 * Searches as a reader would, then follows the link of the result at $place, as a browser would:
 * without its #fragment, which the browser keeps for the address it is sent on to.
 *
 * @return array{link: string, response: TestResponse<Response>}
 */
function openResult(TestCase $test, string $words, int $place): array
{
    $page = ReaderPage::of($test->withHeader('User-Agent', A_READER)->get('/search?q='.rawurlencode($words)));
    $link = $page->links('.results .result-title a')[$place - 1][1];

    return ['link' => $link, 'response' => $test->withHeader('User-Agent', A_READER)->get(strtok($link, '#'))];
}

beforeEach(function () {
    config(['help.going_public' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00', 'Europe/London'));

    content()
        ->article('receive-a-delivery', ['title' => 'Receive a delivery'],
            "## Record damaged stock {#damage}\n\nSay what is damaged.\n")
        ->article('book-in-a-delivery', ['title' => 'Book in a delivery'])
        ->article('count-stock', ['title' => 'Count stock'])
        ->publish();
});

it('records a Search left alone as Not opened, with its words, results shown, date and where', function () {
    $this->withHeader('User-Agent', A_READER)->get('/search?q=delivery')->assertOk();

    expect(searchRecordsOn('2026-11-03'))->toBe([[
        'words' => 'delivery',
        'results_shown' => 2,
        'glossary_entry_shown' => false,
        'outcome' => 'Not opened',
        'opened_result' => null,
        'opened_place' => null,
        'day' => '2026-11-03',
        'where' => 'help centre',
    ]]);
});

it('saves an email address, phone number, postcode and long number only as markers', function () {
    $this->withHeader('User-Agent', A_READER)
        ->get('/search?q='.rawurlencode('delivery for sarah.jones@trentside.co.uk call 0115 496 0000 at NG7 2RD ref 40012345'));

    expect(searchRecordsOn('2026-11-03')[0]['words'])
        ->toBe('delivery for [email] call [phone] at [postcode] ref [number]');
});

it('cleans each kind of personal detail however it is written', function (string $typed, string $saved) {
    $this->withHeader('User-Agent', A_READER)->get('/search?q='.rawurlencode($typed));

    expect(searchRecordsOn('2026-11-03')[0]['words'])->toBe($saved);
})->with([
    'email in capitals' => ['SARAH@TRENTSIDE.EXAMPLE delivery', '[email] delivery'],
    'email with a plus' => ['ops+goods-in@trentside.example', '[email]'],
    'mobile' => ['07700 900123 not answering', '[phone] not answering'],
    'international mobile' => ['+44 7700 900123', '[phone]'],
    'landline with (0)' => ['+44 (0)115 496 0000', '[phone]'],
    'landline in brackets' => ['(0115) 4960000', '[phone]'],
    'phone with hyphens' => ['0115-496-0000', '[phone]'],
    'postcode without a space' => ['ng72rd', '[postcode]'],
    'London postcode' => ['deliver to SW1A 1AA today', 'deliver to [postcode] today'],
    'postcode in lower case' => ['m1 1ae', '[postcode]'],
    'reference with letters' => ['PO-2026-000417 not received', 'PO-[number] not received'],
    'reference stuck to letters' => ['SC00012345', 'SC[number]'],
    'card number in groups' => ['4111 1111 1111 1111', '[number]'],
    'six digits' => ['order 123456', 'order [number]'],
]);

it('keeps short numbers, codes and ordinary words', function (string $typed) {
    $this->withHeader('User-Agent', A_READER)->get('/search?q='.rawurlencode($typed));

    expect(searchRecordsOn('2026-11-03')[0]['words'])->toBe($typed);
})->with([
    'a quantity' => ['receive 240 cartons'],
    'a five-digit code' => ['ASN-00042 delivery'],
    'a year' => ['delivery notes 2026'],
    'a location code' => ['location A1-03-2 too small'],
    'an outward code alone' => ['NG7 delivery'],
    'an at sign between words' => ['stock @ dock'],
]);

it('marks the Search Opened with the place of the result opened, and sends the reader to the Article', function () {
    ['link' => $link, 'response' => $response] = openResult($this, 'delivery', 2);

    $response->assertRedirect('/articles/receive-a-delivery');
    expect($link)->not->toStartWith('/articles/')
        ->and(searchRecordsOn('2026-11-03'))->toBe([[
            'words' => 'delivery',
            'results_shown' => 2,
            'glossary_entry_shown' => false,
            'outcome' => 'Opened',
            'opened_result' => '/articles/receive-a-delivery',
            'opened_place' => 2,
            'day' => '2026-11-03',
            'where' => 'help centre',
        ]]);
});

it('opens a result at its Section, adding nothing to the Article\'s own address', function () {
    ['link' => $link, 'response' => $response] = openResult($this, 'damaged', 1);

    // The browser carries the link's #fragment on to the address it is redirected to.
    $response->assertRedirect('/articles/receive-a-delivery');
    expect(parse_url($link, PHP_URL_FRAGMENT))->toBe('damage')
        ->and($response->headers->get('Location'))->toEndWith('/articles/receive-a-delivery')
        ->and(searchRecordsOn('2026-11-03')[0])->toMatchArray(['outcome' => 'Opened', 'opened_place' => 1]);
});

it('keeps the first result opened when the reader comes back and opens another', function () {
    $page = ReaderPage::of($this->withHeader('User-Agent', A_READER)->get('/search?q=delivery'));
    [$first, $second] = array_column($page->links('.results .result-title a'), 1);

    $this->withHeader('User-Agent', A_READER)->get($first)->assertRedirect('/articles/book-in-a-delivery');
    $this->withHeader('User-Agent', A_READER)->get($second)->assertRedirect('/articles/receive-a-delivery');

    expect(searchRecordsOn('2026-11-03'))->toHaveCount(1)
        ->and(searchRecordsOn('2026-11-03')[0])->toMatchArray([
            'outcome' => 'Opened', 'opened_result' => '/articles/book-in-a-delivery', 'opened_place' => 1,
        ]);
});

it('records a Search that found nothing as No results', function () {
    $this->withHeader('User-Agent', A_READER)->get('/search?q=forklift')->assertOk();

    expect(searchRecordsOn('2026-11-03'))->toBe([[
        'words' => 'forklift',
        'results_shown' => 0,
        'glossary_entry_shown' => false,
        'outcome' => 'No results',
        'opened_result' => null,
        'opened_place' => null,
        'day' => '2026-11-03',
        'where' => 'help centre',
    ]]);
});

it('records each settled Search, so a refined search is a second one', function () {
    $this->withHeader('User-Agent', A_READER)->get('/search?q=delivery');
    $this->withHeader('User-Agent', A_READER)->get('/search?q=book+in+a+delivery');

    expect(array_column(searchRecordsOn('2026-11-03'), 'words'))->toBe(['book in a delivery', 'delivery']);
});

it('records nothing for an empty search box', function () {
    $this->withHeader('User-Agent', A_READER)->get('/search?q=+')->assertOk();

    expect(searchRecordsOn('2026-11-03'))->toBe([]);
});

it('leaves obvious bots out', function () {
    $this->withHeader('User-Agent', 'Googlebot/2.1 (+http://www.google.com/bot.html)')->get('/search?q=delivery')->assertOk();

    expect(searchRecordsOn('2026-11-03'))->toBe([]);
});

it('records nothing while going public is off, and still sends the reader to the result', function () {
    config(['help.going_public' => false]);

    ['response' => $response] = openResult($this, 'delivery', 1);

    $response->assertRedirect('/articles/book-in-a-delivery');
    expect(searchRecordsOn('2026-11-03'))->toBe([]);
});

it('records a Search when the plain form is sent with script off', function () {
    $request = ScriptlessBrowser::submitSearch($this->withHeader('User-Agent', A_READER)->get('/search'), 'book in');

    $this->withHeader('User-Agent', A_READER)->get($request)->assertOk();

    expect(searchRecordsOn('2026-11-03'))->toHaveCount(1)
        ->and(searchRecordsOn('2026-11-03')[0])->toMatchArray(['words' => 'book in', 'outcome' => 'Not opened']);
});

it('holds only the date and keeps no request detail anywhere', function () {
    $reader = [
        'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0 reader-agent-7f3a',
        'Referer' => 'https://example.test/came-from-9c2e',
        'Cookie' => 'tracker=cookie-value-5b1d',
        'Accept-Language' => 'en-GB-x-lang-3e8f',
        'X-Forwarded-For' => '198.51.100.23',
    ];
    $page = ReaderPage::of($this->withHeaders($reader)->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
        ->get('/search?q=delivery'));
    $this->withHeaders($reader)->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
        ->get(strtok($page->links('.results .result-title a')[0][1], '#'))->assertRedirect();

    $row = (array) DB::table('search_records')->sole();
    expect(array_keys($row))->toEqualCanonicalizing([
        'id', 'words', 'results_shown', 'glossary_entry_shown', 'outcome', 'opened_result', 'opened_place', 'day', 'where_made',
    ])
        ->and(Str::isUuid($row['id'], 4))->toBeTrue()
        ->and($row['day'])->toStartWith('2026-11-03');

    $everything = collect(Schema::getTableListing(schemaQualified: false))
        ->reject(fn (string $table): bool => $table === 'migrations')
        ->map(fn (string $table): string => json_encode(DB::table($table)->get(), JSON_UNESCAPED_SLASHES) ?: '')
        ->implode("\n");
    foreach (['reader-agent-7f3a', 'came-from-9c2e', 'cookie-value-5b1d', 'lang-3e8f', '198.51.100.23', '203.0.113.42', '10:00'] as $detail) {
        expect($everything)->not->toContain($detail);
    }
});

it('records a Search the script settles once, however often it is then sent', function () {
    $settled = $this->withHeaders(postedFromAHelpPage())->postJson('/searches', ['q' => 'delivery']);

    $settled->assertCreated()->assertHeader('X-Robots-Tag', 'noindex');
    $search = $settled->json('search');
    expect(Str::isUuid($search, 4))->toBeTrue()
        ->and(array_map(fn (array $result): array => array_intersect_key($result, array_flip(['place', 'title', 'address', 'opens_at'])), $settled->json('results')))->toBe([
            ['place' => 1, 'title' => 'Book in a delivery', 'address' => "/searches/{$search}/opened/1/articles/book-in-a-delivery", 'opens_at' => '/articles/book-in-a-delivery'],
            ['place' => 2, 'title' => 'Receive a delivery', 'address' => "/searches/{$search}/opened/2/articles/receive-a-delivery", 'opens_at' => '/articles/receive-a-delivery'],
        ]);

    // The reader then presses Enter: the form names the Search already settled.
    $page = ReaderPage::of($this->withHeader('User-Agent', A_READER)->get("/search?q=delivery&search={$search}"));
    $this->withHeader('User-Agent', A_READER)->get($page->links('.results .result-title a')[1][1])
        ->assertRedirect('/articles/receive-a-delivery');

    expect(searchRecordsOn('2026-11-03'))->toHaveCount(1)
        ->and(searchRecordsOn('2026-11-03')[0])->toMatchArray(['words' => 'delivery', 'outcome' => 'Opened', 'opened_place' => 2]);
});

it('records a new Search when the words changed after the script settled them', function () {
    $search = $this->withHeaders(postedFromAHelpPage())->postJson('/searches', ['q' => 'delivery'])->json('search');

    $this->withHeader('User-Agent', A_READER)->get("/search?q=count+stock&search={$search}")->assertOk();

    expect(array_column(searchRecordsOn('2026-11-03'), 'words'))->toBe(['count stock', 'delivery']);
});

it('settles nothing from the script for an empty search box', function () {
    $this->withHeaders(postedFromAHelpPage())->postJson('/searches', ['q' => ' '])
        ->assertOk()->assertExactJson(['search' => null, 'glossary_answers' => [], 'results' => []]);

    expect(searchRecordsOn('2026-11-03'))->toBe([]);
});

it('sets no cookie and asks not to be indexed on the search service\'s own addresses', function () {
    $settled = $this->withHeaders(postedFromAHelpPage())->postJson('/searches', ['q' => 'delivery']);
    $opened = $this->withHeader('User-Agent', A_READER)->get($settled->json('results.0.address'));

    foreach ([$settled, $opened] as $response) {
        expect($response->headers->getCookies())->toBeEmpty()
            ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex');
    }
});

describe('the recording limit', function () {
    it('stops recording one reader\'s Searches past the limit, still showing them their results', function () {
        $from = fn (string $reader) => $this->withServerVariables(['REMOTE_ADDR' => $reader]);
        foreach (range(1, SearchLimit::SEARCHES_ALLOWED) as $search) {
            $from('203.0.113.9')->withHeader('User-Agent', A_READER)->get('/search?q=delivery')->assertOk();
        }

        // Past the limit the results page and the script's settling still answer, with each
        // result leading straight to its Article, but nothing more is recorded.
        $page = ReaderPage::of($from('203.0.113.9')->withHeader('User-Agent', A_READER)->get('/search?q=count+stock'));
        $settled = $from('203.0.113.9')->withHeaders(postedFromAHelpPage())->postJson('/searches', ['q' => 'count stock']);

        expect($page->links('.results .result-title a'))->toBe([['Count stock', '/articles/count-stock']])
            ->and($settled->status())->toBe(200)
            ->and($settled->json('search'))->toBeNull()
            ->and($settled->json('results.0.address'))->toBe('/articles/count-stock');

        // Another reader's Searches are still recorded.
        $from('198.51.100.23')->withHeader('User-Agent', A_READER)->get('/search?q=count+stock')->assertOk();

        expect(array_count_values(array_column(searchRecordsOn('2026-11-03'), 'words')))
            ->toBe(['count stock' => 1, 'delivery' => SearchLimit::SEARCHES_ALLOWED]);
    });

    it('records the reader\'s Searches again once the minutes are up', function () {
        foreach (range(1, SearchLimit::SEARCHES_ALLOWED + 1) as $search) {
            $this->withHeaders(postedFromAHelpPage())->postJson('/searches', ['q' => 'delivery']);
        }

        $this->travel(SearchLimit::SEARCH_MINUTES)->minutes();
        $this->withHeader('User-Agent', A_READER)->get('/search?q=count+stock')->assertOk();

        expect(array_count_values(array_column(searchRecordsOn('2026-11-03'), 'words')))
            ->toBe(['count stock' => 1, 'delivery' => SearchLimit::SEARCHES_ALLOWED]);
    });
});

describe('a Search from an earlier day', function () {
    // A month's totals are added up once it is over (Records::totalUp()), so a record is never
    // changed after its own UK day: a Search or a vote can be updated only on the day it was made.

    it('records a new Search when a results page with a settled Search\'s id is reloaded the next day', function () {
        $search = $this->withHeaders(postedFromAHelpPage())->postJson('/searches', ['q' => 'delivery'])->json('search');
        $this->travelTo(CarbonImmutable::parse('2026-11-04 00:05', 'Europe/London'));

        $page = ReaderPage::of($this->withHeader('User-Agent', A_READER)->get("/search?q=delivery&search={$search}"));

        expect($page->links('.results .result-title a')[0][1])->not->toContain($search)
            ->and(searchRecordsOn('2026-11-03'))->toHaveCount(1)
            ->and(searchRecordsOn('2026-11-03')[0])->toMatchArray(['words' => 'delivery', 'results_shown' => 2])
            ->and(searchRecordsOn('2026-11-04'))->toHaveCount(1);
    });

    it('leaves a Search Not opened when its result is opened after its day is over', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-30 23:58', 'Europe/London'));
        $page = ReaderPage::of($this->withHeader('User-Agent', A_READER)->get('/search?q=delivery'));
        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:01', 'Europe/London'));

        $this->withHeader('User-Agent', A_READER)->get(strtok($page->links('.results .result-title a')[0][1], '#'))
            ->assertRedirect('/articles/book-in-a-delivery');

        expect(searchRecordsOn('2026-11-30'))->toHaveCount(1)
            ->and(searchRecordsOn('2026-11-30')[0])->toMatchArray(['outcome' => 'Not opened', 'opened_place' => null])
            ->and(app(Records::class)->monthlyTotals(CarbonImmutable::parse('2026-11-15'))?->searches(SearchOutcome::Opened))->toBe(0);
    });

    it('still marks a Search Opened later on its own day', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 00:00', 'Europe/London'));
        $page = ReaderPage::of($this->withHeader('User-Agent', A_READER)->get('/search?q=delivery'));
        $this->travelTo(CarbonImmutable::parse('2026-11-03 23:59', 'Europe/London'));

        $this->withHeader('User-Agent', A_READER)->get(strtok($page->links('.results .result-title a')[0][1], '#'));

        expect(searchRecordsOn('2026-11-03')[0])->toMatchArray(['outcome' => 'Opened', 'opened_place' => 1]);
    });
});
