<?php

use App\Records\Records;
use App\Site\Asset;
use Carbon\CarbonImmutable;
use Tests\Support\ReaderBrowser;
use Tests\Support\ReaderPage;

/*
 * Page View Counts (spec #30, "Feedback, Still stuck? and counts"; user stories 98, 155, 156,
 * 158): the help centre's own count of successful page views, per page per day, made on its
 * server with no counting script on the page, obvious bots left out, and only once going public is on.
 */

/** @return array<string, int> */
function pageViewsOn(string $day): array
{
    $date = CarbonImmutable::parse($day);

    return app(Records::class)->pageViews($date, $date);
}

beforeEach(function () {
    config(['help.going_public' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00', 'Europe/London'));

    content()
        ->article('receive-at-the-dock')
        ->article('count-stock')
        ->article('an-old-way', ['status' => 'Retired', 'what_changed' => 'First Mile Manager no longer works this way.'])
        ->publish();
});

it('adds one to a page\'s count for the day for each view', function () {
    $this->withHeader('User-Agent', A_READER)->get('/articles/receive-at-the-dock')->assertOk();
    $this->withHeader('User-Agent', A_READER)->get('/articles/receive-at-the-dock')->assertOk();
    $this->withHeader('User-Agent', A_READER)->get('/articles/count-stock')->assertOk();

    expect(pageViewsOn('2026-11-03'))->toBe([
        '/articles/receive-at-the-dock' => 2,
        '/articles/count-stock' => 1,
    ]);
});

it('counts a page by its address, never its query string', function () {
    $this->withHeader('User-Agent', A_READER)->get('/articles/receive-at-the-dock?utm_source=email&ref=jo@example.com')->assertOk();

    expect(pageViewsOn('2026-11-03'))->toBe(['/articles/receive-at-the-dock' => 1]);
});

it('counts each day on its own, by the day in the UK', function () {
    $this->withHeader('User-Agent', A_READER)->get('/articles/receive-at-the-dock');

    // Either side of midnight in the UK, in winter (GMT) and in summer (BST, an hour ahead of UTC).
    $this->travelTo(CarbonImmutable::parse('2026-11-03 23:30', 'Europe/London'));
    $this->withHeader('User-Agent', A_READER)->get('/articles/receive-at-the-dock');
    $this->travelTo(CarbonImmutable::parse('2026-11-04 00:30', 'Europe/London'));
    $this->withHeader('User-Agent', A_READER)->get('/articles/receive-at-the-dock');
    $this->travelTo(CarbonImmutable::parse('2027-07-01 23:30', 'UTC')); // 00:30 on 2 July in the UK
    $this->withHeader('User-Agent', A_READER)->get('/articles/receive-at-the-dock');

    expect(pageViewsOn('2026-11-03'))->toBe(['/articles/receive-at-the-dock' => 2])
        ->and(pageViewsOn('2026-11-04'))->toBe(['/articles/receive-at-the-dock' => 1])
        ->and(pageViewsOn('2027-07-01'))->toBe([])
        ->and(pageViewsOn('2027-07-02'))->toBe(['/articles/receive-at-the-dock' => 1])
        ->and(app(Records::class)->pageViews(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30')))
        ->toBe(['/articles/receive-at-the-dock' => 3]);
});

it('leaves out obvious bots', function (?string $userAgent) {
    $this->withHeader('User-Agent', (string) $userAgent)->get('/articles/receive-at-the-dock')->assertOk();

    expect(pageViewsOn('2026-11-03'))->toBe([]);
})->with([
    'Googlebot' => 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'Bingbot' => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
    'an AI crawler' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)',
    'a link preview' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
    'an uptime check' => 'Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)',
    'a speed check' => 'Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36 Chrome-Lighthouse',
    'a headless browser' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/141.0.0.0 Safari/537.36',
    'curl' => 'curl/8.11.1',
    'a script' => 'python-requests/2.32.3',
    'no user agent' => '',
]);

it('counts only successful page views', function (string $method, string $address, int $status) {
    $this->withHeader('User-Agent', A_READER)->call($method, $address)->assertStatus($status);

    expect(pageViewsOn('2026-11-03'))->toBe([]);
})->with([
    'page not found' => ['GET', '/articles/no-such-article', 404],
    'no such address' => ['GET', '/no/such/page', 404],
    'a Retired Article\'s notice' => ['GET', '/articles/an-old-way', 410],
    'robots.txt' => ['GET', '/robots.txt', 200],
    'a HEAD request' => ['HEAD', '/articles/receive-at-the-dock', 200],
]);

it('counts nothing until going public is on', function () {
    config(['help.going_public' => false]);

    $this->withHeader('User-Agent', A_READER)->get('/articles/receive-at-the-dock')->assertOk();

    expect(pageViewsOn('2026-11-03'))->toBe([]);
});

it('keeps only the page, the day and the number', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeaders(['User-Agent' => A_READER, 'Referer' => 'https://example.com/a-page'])
        ->get('/articles/receive-at-the-dock?q=jo@example.com');

    expect(DB::table('page_view_counts')->get()->map(fn (object $row): array => (array) $row)->all())
        ->toBe([['page' => '/articles/receive-at-the-dock', 'day' => '2026-11-03', 'views' => 1]]);
});

it('counts with no script: the page\'s only script is the site\'s own, which asks for nothing but the page\'s own files when it opens', function () {
    $response = $this->withHeader('User-Agent', A_READER)->get('/articles/receive-at-the-dock');
    $scripts = array_map(fn (array $script): ?string => $script['src'] ?? null, ReaderPage::of($response)->attributes('script'));

    expect($scripts)->toHaveCount(1)
        ->and($scripts[0])->toBe('/assets/'.Asset::fingerprint('js/site.js').'/js/site.js')
        ->and(array_values(array_filter(
            ReaderBrowser::visit($this, '/articles/receive-at-the-dock', script: true)->requests,
            fn (string $request): bool => ! str_starts_with($request, 'GET /assets/'),
        )))->toBe(['GET /articles/receive-at-the-dock']);
});

it('leaves pages out of shared caches, so every view reaches the server', function () {
    $response = $this->withHeader('User-Agent', A_READER)->get('/articles/receive-at-the-dock');

    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-cache');
});
