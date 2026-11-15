<?php

use App\Providers\AppServiceProvider;
use App\Records\Records;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Facade;
use Illuminate\Testing\TestResponse;
use Tests\Support\BrowserLayout;
use Tests\Support\ReaderPage;

/*
 * Article Feedback (spec #30; user stories 62 to 67, 73, 74): every Article ends by asking "Did
 * this answer your question?". Yes says thanks; No asks what was wrong and offers a next step.
 * Each vote records the Article, the answer, any Feedback Reason and comment, the Article's
 * Checked On date at the time, the day and where it was given, and nothing about the reader.
 */

/**
 * Answers as a reader's browser does from one of the help centre's own pages: a plain form post,
 * which browsers send with the page's origin.
 *
 * @param  array<string, string>  $form
 */
function answer(string $address, array $form, array $headers = []): TestResponse
{
    return test()->withHeaders([
        'User-Agent' => A_READER,
        'Origin' => rtrim(url('/'), '/'),
        'Sec-Fetch-Site' => 'same-origin',
        ...$headers,
    ])->post($address, $form);
}

/** @return list<array<string, mixed>> */
function votesOn(string $day): array
{
    $date = CarbonImmutable::parse($day);

    return array_map(fn ($vote): array => $vote->toArray(), app(Records::class)->votes($date, $date));
}

beforeEach(function () {
    config(['help.going_public' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00', 'Europe/London'));

    content()
        ->article('receive-at-the-dock', ['title' => 'Receive a delivery', 'checked_on' => '2026-10-24', 'related_articles' => ['count-stock', 'follow-your-deliveries']])
        ->article('count-stock', ['checked_on' => '2026-09-30', 'next_review_on' => '2027-03-30'])
        ->article('follow-your-deliveries', ['audience_tier' => 'Client', 'team_roles' => null, 'related_articles' => ['see-your-stock', 'count-stock']])
        ->article('see-your-stock', ['audience_tier' => 'Client', 'team_roles' => null])
        ->publish();
});

it('asks "Did this answer your question?" at the end of every Article, as a plain form', function () {
    $page = ReaderPage::of($this->withHeader('User-Agent', A_READER)->get('/articles/receive-at-the-dock'));

    expect($page->text('.feedback'))->toBe('Did this answer your question? Yes No')
        ->and($page->texts('.feedback form[method=post][action="/feedback/receive-at-the-dock"] button[name=answer]'))->toBe(['Yes', 'No'])
        ->and($page->has('.feedback input[name=_token]'))->toBeFalse();
});

it('says thanks for a Yes and records it with the Article\'s Checked On date', function () {
    $page = ReaderPage::of(answer('/feedback/receive-at-the-dock', ['answer' => 'yes']));

    expect($page->text('.feedback'))->toBe('Thanks')
        ->and($page->links('.feedback-back a'))->toBe([['Back to “Receive a delivery”', '/articles/receive-at-the-dock']])
        ->and(votesOn('2026-11-03'))->toBe([[
            'article' => 'receive-at-the-dock',
            'answer' => 'Yes',
            'reason' => null,
            'comment' => null,
            'checked_on' => '2026-10-24',
            'day' => '2026-11-03',
            'where' => 'help centre',
        ]]);
});

it('asks what was wrong after a No, with the Feedback Reasons, a privacy hint and next steps', function () {
    $page = ReaderPage::of(answer('/feedback/receive-at-the-dock', ['answer' => 'no']));

    expect($page->text('.feedback-title'))->toBe('What was wrong?')
        ->and($page->text('.feedback-reason legend'))->toBe('Choose a reason (optional)')
        ->and($page->texts('.feedback-reason label.choice'))->toBe([
            "It doesn't match what I see in First Mile Manager",
            "It didn't answer my question",
            'It was hard to follow',
            'Something else',
        ])
        ->and($page->text('.feedback-comment'))->toBe("Anything to add? (optional) Up to 500 characters. Please don't include names, emails or order details.")
        ->and($page->links('.feedback-next .linklist a'))->toBe([
            ['Count stock How-to', '/articles/count-stock'],
            ['Follow your deliveries How-to', '/articles/follow-your-deliveries'],
        ])
        ->and($page->has('.feedback-next form[role=search][action="/search"]'))->toBeTrue()
        ->and($page->text('.still-stuck'))->toBe('Still stuck? Email hello@firstmilemanager.co.uk')
        ->and(votesOn('2026-11-03'))->toBe([[
            'article' => 'receive-at-the-dock',
            'answer' => 'No',
            'reason' => null,
            'comment' => null,
            'checked_on' => '2026-10-24',
            'day' => '2026-11-03',
            'where' => 'help centre',
        ]]);
});

it('words the first reason without Stockist on a Client-tier Article, and offers only Client-tier help', function () {
    $response = answer('/feedback/follow-your-deliveries', ['answer' => 'no']);
    $page = ReaderPage::of($response);

    expect($page->texts('.feedback-reason label.choice')[0])->toBe("It doesn't match what I see")
        ->and($page->links('.feedback-next .linklist a'))->toBe([['See your stock How-to', '/articles/see-your-stock']])
        ->and($page->text('.still-stuck'))->toBe('Still stuck? Contact your courier’s account manager.');
    // The header and footer are the help centre's own; the feedback itself never names Stockist.
    expect($page->text('main'))->not->toContain('Stockist');
});

it('adds the reader\'s Feedback Reason and comment to their No', function () {
    $page = ReaderPage::of(answer('/feedback/receive-at-the-dock', ['answer' => 'no']));
    $vote = (string) $page->value('.feedback-reason input[name=vote]');

    $thanks = ReaderPage::of(answer('/feedback/receive-at-the-dock/reason', [
        'vote' => $vote,
        'reason' => "It doesn't match what I see",
        'comment' => "  The Receive button is called Book in now.\r\nSecond line.  ",
    ]));

    expect($thanks->text('.feedback-title'))->toBe('Thanks for telling us')
        ->and($thanks->links('.feedback-next .linklist a'))->toHaveCount(2)
        ->and($thanks->has('.feedback-next form[role=search]'))->toBeTrue()
        ->and(votesOn('2026-11-03'))->toBe([[
            'article' => 'receive-at-the-dock',
            'answer' => 'No',
            'reason' => "It doesn't match what I see",
            'comment' => "The Receive button is called Book in now.\nSecond line.",
            'checked_on' => '2026-10-24',
            'day' => '2026-11-03',
            'where' => 'help centre',
        ]]);
});

it('takes a reason and comment once, only for a No', function () {
    $no = (string) ReaderPage::of(answer('/feedback/receive-at-the-dock', ['answer' => 'no']))->value('.feedback-reason input[name=vote]');
    answer('/feedback/receive-at-the-dock/reason', ['vote' => $no, 'reason' => 'It was hard to follow'])->assertOk();
    answer('/feedback/receive-at-the-dock/reason', ['vote' => $no, 'reason' => 'Something else', 'comment' => 'Changed my mind'])->assertOk();
    answer('/feedback/receive-at-the-dock/reason', ['vote' => 'not-a-vote', 'reason' => 'Something else'])->assertOk();

    expect(array_column(votesOn('2026-11-03'), 'reason'))->toBe(['It was hard to follow'])
        ->and(array_column(votesOn('2026-11-03'), 'comment'))->toBe([null]);
});

it('refuses a comment over 500 characters with a plain message, keeping what was written', function () {
    $vote = (string) ReaderPage::of(answer('/feedback/receive-at-the-dock', ['answer' => 'no']))->value('.feedback-reason input[name=vote]');
    $comment = str_repeat('é', 501);

    $page = ReaderPage::of(answer('/feedback/receive-at-the-dock/reason', ['vote' => $vote, 'reason' => 'Something else', 'comment' => $comment]), status: 422);

    expect($page->text('.feedback-problem'))->toBe('Your comment is 501 characters long. Please shorten it to 500 characters or fewer and send it again.')
        ->and($page->text('.feedback-reason textarea'))->toBe($comment)
        ->and($page->value('.feedback-reason input[name=reason]:checked'))->toBe('Something else')
        ->and($page->value('.feedback-reason input[name=vote]'))->toBe($vote)
        ->and(array_column(votesOn('2026-11-03'), 'comment'))->toBe([null]);

    answer('/feedback/receive-at-the-dock/reason', ['vote' => $vote, 'reason' => 'Something else', 'comment' => str_repeat('é', 500)])->assertOk();
    expect(mb_strlen((string) votesOn('2026-11-03')[0]['comment']))->toBe(500);
});

it('takes a reason only on the day of its No, since a past day\'s votes may already be added up', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-30 23:58', 'Europe/London'));
    $no = (string) ReaderPage::of(answer('/feedback/receive-at-the-dock', ['answer' => 'no']))->value('.feedback-reason input[name=vote]');
    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:02', 'Europe/London'));

    $thanks = ReaderPage::of(answer('/feedback/receive-at-the-dock/reason', ['vote' => $no, 'reason' => 'It was hard to follow', 'comment' => 'Too long']));

    expect($thanks->text('.feedback-title'))->toBe('Thanks for telling us')
        ->and(votesOn('2026-11-30'))->toHaveCount(1)
        ->and(votesOn('2026-11-30')[0])->toMatchArray(['answer' => 'No', 'reason' => null, 'comment' => null])
        ->and(votesOn('2026-12-01'))->toBe([]);
});

it('takes a reason only for a vote on the Article it is posted for', function () {
    $no = (string) ReaderPage::of(answer('/feedback/receive-at-the-dock', ['answer' => 'no']))->value('.feedback-reason input[name=vote]');

    answer('/feedback/count-stock/reason', ['vote' => $no, 'reason' => 'It was hard to follow'])->assertOk();

    expect(votesOn('2026-11-03')[0])->toMatchArray(['article' => 'receive-at-the-dock', 'reason' => null]);
});

it('records each vote with the Checked On date the Article had at the time', function () {
    answer('/feedback/count-stock', ['answer' => 'yes']);
    content()
        ->article('count-stock', ['checked_on' => '2026-11-02', 'next_review_on' => '2027-05-02'])
        ->publish();
    answer('/feedback/count-stock', ['answer' => 'yes']);

    expect(array_column(votesOn('2026-11-03'), 'checked_on'))->toBe(['2026-09-30', '2026-11-02']);
});

it('refuses a post from another site, recording nothing', function (array $headers) {
    $response = $this->withHeaders(['User-Agent' => A_READER, ...$headers])
        ->post('/feedback/receive-at-the-dock', ['answer' => 'yes']);

    $response->assertForbidden()->assertSee('came from another website')->assertHeader('X-Robots-Tag', 'noindex');
    expect(votesOn('2026-11-03'))->toBe([]);
})->with([
    'another site, by Sec-Fetch-Site' => [['Origin' => 'https://example.com', 'Sec-Fetch-Site' => 'cross-site']],
    'a sister site, by Sec-Fetch-Site' => [['Sec-Fetch-Site' => 'same-site']],
    'another site, by Origin alone' => [['Origin' => 'https://example.com']],
    'no origin at all' => [[]],
]);

it('refuses a reason from another site too', function () {
    $vote = (string) ReaderPage::of(answer('/feedback/receive-at-the-dock', ['answer' => 'no']))->value('.feedback-reason input[name=vote]');

    $this->withHeaders(['User-Agent' => A_READER, 'Origin' => 'https://example.com', 'Sec-Fetch-Site' => 'cross-site'])
        ->post('/feedback/receive-at-the-dock/reason', ['vote' => $vote, 'reason' => 'Something else'])
        ->assertForbidden();

    expect(array_column(votesOn('2026-11-03'), 'reason'))->toBe([null]);
});

it('accepts a post whose Origin is the help centre\'s, from a browser that sends no Sec-Fetch-Site', function () {
    $this->withHeaders(['User-Agent' => A_READER, 'Origin' => rtrim(url('/'), '/')])
        ->post('/feedback/receive-at-the-dock', ['answer' => 'yes'])
        ->assertOk();

    expect(votesOn('2026-11-03'))->toHaveCount(1);
});

it('has no feedback for a Draft or an unknown Article', function () {
    content()
        ->article('receive-at-the-dock')
        ->article('a-draft', ['status' => 'Draft'])
        ->publish();

    answer('/feedback/a-draft', ['answer' => 'yes'])->assertNotFound();
    answer('/feedback/no-such-article', ['answer' => 'yes'])->assertNotFound();
    expect(votesOn('2026-11-03'))->toBe([]);
});

it('says thanks but saves nothing until going public is on', function () {
    config(['help.going_public' => false]);

    ReaderPage::of(answer('/feedback/receive-at-the-dock', ['answer' => 'yes']));
    $no = ReaderPage::of(answer('/feedback/receive-at-the-dock', ['answer' => 'no']));
    $thanks = ReaderPage::of(answer('/feedback/receive-at-the-dock/reason', ['reason' => 'Something else', 'comment' => 'Hello']));

    expect($no->has('.feedback-reason input[name=vote]'))->toBeFalse()
        ->and($thanks->text('.feedback-title'))->toBe('Thanks for telling us')
        ->and(DB::table('article_feedback')->count())->toBe(0);
});

it('sets no cookie on any answer, and says noindex', function () {
    $responses = [
        $this->withHeader('User-Agent', A_READER)->get('/articles/receive-at-the-dock'),
        answer('/feedback/receive-at-the-dock', ['answer' => 'yes']),
        $no = answer('/feedback/receive-at-the-dock', ['answer' => 'no']),
        answer('/feedback/receive-at-the-dock/reason', ['vote' => (string) ReaderPage::of($no)->value('.feedback-reason input[name=vote]'), 'comment' => str_repeat('x', 501)]),
        $this->withHeaders(['User-Agent' => A_READER, 'Origin' => 'https://example.com'])->post('/feedback/receive-at-the-dock', ['answer' => 'yes']),
    ];

    foreach ($responses as $response) {
        expect($response->headers->getCookies())->toBeEmpty();
    }
    foreach (array_slice($responses, 1) as $response) {
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }
});

it('keeps only the vote\'s own details, never the reader\'s', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeaders([
            'User-Agent' => A_READER,
            'Origin' => rtrim(url('/'), '/'),
            'Referer' => 'https://help.firstmilemanager.co.uk/articles/receive-at-the-dock',
        ])
        ->post('/feedback/receive-at-the-dock', ['answer' => 'yes']);

    $row = (array) DB::table('article_feedback')->first();
    expect(array_keys($row))->toBe(['id', 'article', 'answer', 'reason', 'comment', 'checked_on', 'day', 'where_given', 'sent_to_issue'])
        ->and(implode(' ', array_map(strval(...), $row)))->not->toContain('203.0.113')->not->toContain('Mozilla')->not->toContain(':');
});

it('leaves obvious bots\' votes out', function () {
    answer('/feedback/receive-at-the-dock', ['answer' => 'yes'], ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'])->assertOk();

    expect(votesOn('2026-11-03'))->toBe([]);
});

describe('the rate limit', function () {
    beforeEach(function () {
        // As on the server: the cache in the database, the rate limiter in its own file store,
        // and a log file, so the test sees anything the limiter leaves behind.
        $this->limiterPath = sys_get_temp_dir().'/help-limiter-'.Str::random(8);
        $this->logPath = sys_get_temp_dir().'/help-log-'.Str::random(8).'.log';
        config([
            'cache.default' => 'database',
            'cache.limiter' => 'limiter',
            'cache.stores.limiter.path' => $this->limiterPath,
            'logging.default' => 'single',
            'logging.channels.single.path' => $this->logPath,
        ]);
        app('cache')->forgetDriver('limiter');
        app()->forgetInstance(RateLimiter::class);
        Facade::clearResolvedInstances();
        app()->getProvider(AppServiceProvider::class)?->boot();
    });

    afterEach(function () {
        File::deleteDirectory($this->limiterPath);
        File::delete($this->logPath);
    });

    it('refuses votes past the limit with a plain message, leaving nothing in the database or logs', function () {
        foreach (['yes', 'no', 'yes'] as $vote) {
            answer('/feedback/receive-at-the-dock', ['answer' => $vote])->assertOk();
        }

        $refused = answer('/feedback/receive-at-the-dock', ['answer' => 'yes']);

        $page = ReaderPage::of($refused, status: 429);
        expect($page->text('.feedback'))->toBe("Your answer wasn't counted This article has had several answers from you in the last few minutes, so this one wasn't counted. Please try again later.")
            ->and($refused->headers->getCookies())->toBeEmpty()
            ->and(votesOn('2026-11-03'))->toHaveCount(3)
            ->and(DB::table('cache')->count())->toBe(0)
            ->and(collect(DB::select("select table_name from information_schema.tables where table_schema = 'public'"))
                ->pluck('table_name')
                ->reject(fn (string $table): bool => in_array($table, ['migrations', 'articles', 'facts', 'redirects', 'article_feedback', 'page_view_counts', 'still_stuck_counts'], true))
                ->filter(fn (string $table): bool => DB::table($table)->count() > 0)
                ->values()->all())->toBe([])
            ->and(File::exists($this->logPath) ? File::get($this->logPath) : '')->toBe('');
    });

    it('limits each Article on its own, and refuses another site\'s post before counting it', function () {
        foreach (['yes', 'no', 'yes'] as $vote) {
            answer('/feedback/receive-at-the-dock', ['answer' => $vote]);
        }

        answer('/feedback/count-stock', ['answer' => 'yes'])->assertOk();
        answer('/feedback/receive-at-the-dock', ['answer' => 'yes'], ['Origin' => 'https://example.com', 'Sec-Fetch-Site' => 'cross-site'])->assertForbidden();
    });

    it('gives each reader behind Cloudflare a limit of their own', function () {
        // Every reader reaches the help centre through Cloudflare (ADR-0002), so a vote arrives
        // from a Cloudflare edge address, with the reader's own address in X-Forwarded-For.
        $through = fn (string $reader): array => [
            'REMOTE_ADDR' => '172.70.1.7',
            'HTTP_X_FORWARDED_FOR' => $reader,
        ];
        foreach (['yes', 'no', 'yes'] as $vote) {
            $this->withServerVariables($through('203.0.113.9'));
            answer('/feedback/receive-at-the-dock', ['answer' => $vote])->assertOk();
        }

        $this->withServerVariables($through('198.51.100.23'));
        answer('/feedback/receive-at-the-dock', ['answer' => 'yes'])->assertOk();
        $this->withServerVariables($through('2001:db8::5'));
        answer('/feedback/receive-at-the-dock', ['answer' => 'yes'])->assertOk();
        $this->withServerVariables($through('203.0.113.9'));
        answer('/feedback/receive-at-the-dock', ['answer' => 'yes'])->assertStatus(429);

        expect(votesOn('2026-11-03'))->toHaveCount(5);
    });

    it('trusts X-Forwarded-For only from Cloudflare, so a reader can\'t name a new address to escape the limit', function () {
        foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3', '198.51.100.4'] as $madeUp) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => $madeUp]);
            $last = answer('/feedback/receive-at-the-dock', ['answer' => 'yes']);
        }

        $last->assertStatus(429);
        expect(votesOn('2026-11-03'))->toHaveCount(3);
    });

    it('counts a vote the script posts again for the same click once, with the same reference', function () {
        // The site's script marks each click with a random token, so posting it again after a
        // lost answer can't count it twice. The token is kept only in the limiter's store.
        $click = ['answer' => 'no', 'once' => '0123456789abcdef0123456789abcdef'];
        $first = ReaderPage::of(answer('/feedback/receive-at-the-dock', $click));
        $again = ReaderPage::of(answer('/feedback/receive-at-the-dock', $click));
        answer('/feedback/receive-at-the-dock', ['answer' => 'no', 'once' => 'fedcba9876543210fedcba9876543210'])->assertOk();

        expect($again->text('.feedback-title'))->toBe('What was wrong?')
            ->and($again->value('.feedback-reason input[name=vote]'))->toBe($first->value('.feedback-reason input[name=vote]'))
            ->and(votesOn('2026-11-03'))->toHaveCount(2)
            ->and(DB::table('cache')->count())->toBe(0)
            ->and(json_encode(DB::table('article_feedback')->get()))->not->toContain('0123456789abcdef');
    });

    it('takes votes again after ten minutes, and forgets the key once its minutes are up', function () {
        foreach (['yes', 'no', 'yes'] as $vote) {
            answer('/feedback/receive-at-the-dock', ['answer' => $vote]);
        }
        expect(File::allFiles($this->limiterPath))->not->toBeEmpty();

        $this->travel(10)->minutes();
        answer('/feedback/receive-at-the-dock', ['answer' => 'yes'])->assertOk();

        $this->travel(11)->minutes();
        $this->artisan('help:forget-rate-limits')->assertSuccessful();
        expect(File::allFiles($this->limiterPath))->toBeEmpty()
            ->and(votesOn('2026-11-03'))->toHaveCount(4);
    });
});

it('fits phones and desktops without sideways scrolling', function (int $width) {
    $article = BrowserLayout::of($this->withHeader('User-Agent', A_READER)->get('/articles/receive-at-the-dock'), width: $width, selectors: ['.feedback']);
    $no = BrowserLayout::of(answer('/feedback/receive-at-the-dock', ['answer' => 'no']), width: $width, selectors: ['.feedback textarea']);

    expect($article->scrollWidth)->toBeLessThanOrEqual($width)
        ->and($no->scrollWidth)->toBeLessThanOrEqual($width)
        ->and($no->box('.feedback textarea')['width'])->toBeLessThanOrEqual($width - 32);
})->with([320, 390, 1280]);

it('asks again when the post has no answer, or an answer it doesn\'t know', function (array $form) {
    $page = ReaderPage::of(answer('/feedback/receive-at-the-dock', $form), status: 422);

    expect($page->text('.feedback'))->toBe('Did this answer your question? Yes No')
        ->and(votesOn('2026-11-03'))->toBe([]);
})->with([
    'no answer' => [[]],
    'a made-up answer' => [['answer' => 'maybe']],
    'a list' => [['answer' => ['yes']]],
]);

it('asks nothing on a Glossary Entry, which has no feedback', function () {
    content()
        ->article('receive-at-the-dock')
        ->glossaryEntry('zone')
        ->clientGlossaryEntry('zone')
        ->publish();

    foreach (['/glossary/zone', '/client-glossary/zone'] as $address) {
        $response = $this->withHeader('User-Agent', A_READER)->get($address)->assertOk();
        expect(ReaderPage::of($response)->has('.feedback'))->toBeFalse();
        $response->assertDontSee('/feedback/', escape: false);
    }
});
