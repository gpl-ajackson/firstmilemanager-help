<?php

use App\Records\Records;
use Carbon\CarbonImmutable;
use Tests\Support\ReaderPage;

/*
 * "Still stuck?" (spec #30; user stories 68, 69, 155): a staff Article sends the reader to
 * hello@firstmilemanager.co.uk with its title in the subject, through the help centre's own "still
 * stuck" address, which adds one to the Article's Still Stuck Count for the day. A Client-tier
 * Article sends the client to their 3PL's account manager: no Stockist address, no count.
 */

/** @return array<string, int> */
function stillStuckOn(string $day): array
{
    $date = CarbonImmutable::parse($day);

    return app(Records::class)->stillStuckCounts($date, $date);
}

beforeEach(function () {
    config(['help.going_public' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00', 'Europe/London'));

    content()
        ->article('receive-at-the-dock', ['title' => 'Receive a delivery & check it'])
        ->article('count-stock')
        ->article('follow-your-deliveries', ['audience_tier' => 'Client'])
        ->article('a-draft', ['status' => 'Draft'])
        ->publish();
});

it('offers staff an email to Stockist, through the still stuck address', function () {
    $page = ReaderPage::of($this->withHeader('User-Agent', A_READER)->get('/articles/receive-at-the-dock'));

    expect($page->text('.still-stuck'))->toBe('Still stuck? Email hello@firstmilemanager.co.uk')
        ->and($page->links('.still-stuck a'))->toBe([['Email hello@firstmilemanager.co.uk', '/still-stuck/receive-at-the-dock']]);
});

it('counts a click and opens an email with the Article\'s title as the subject', function () {
    $this->withHeader('User-Agent', A_READER)->get('/still-stuck/receive-at-the-dock')
        ->assertRedirect('mailto:hello@firstmilemanager.co.uk?subject=Receive%20a%20delivery%20%26%20check%20it');
    $this->withHeader('User-Agent', A_READER)->get('/still-stuck/receive-at-the-dock');
    $this->withHeader('User-Agent', A_READER)->get('/still-stuck/count-stock');

    expect(stillStuckOn('2026-11-03'))->toBe([
        'receive-at-the-dock' => 2,
        'count-stock' => 1,
    ]);
});

it('sends clients to their 3PL\'s account manager, with no Stockist address and no counting link', function () {
    $response = $this->withHeader('User-Agent', A_READER)->get('/articles/follow-your-deliveries');
    $page = ReaderPage::of($response);

    expect($page->text('.still-stuck'))->toBe('Still stuck? Contact your courier’s account manager.')
        ->and($page->links('.still-stuck a'))->toBe([]);
    $response->assertDontSee('@firstmilemanager.co.uk')->assertDontSee('mailto:')->assertDontSee('/still-stuck');
});

it('has no still stuck address for a Client-tier, Draft or unknown Article', function (string $name) {
    $this->withHeader('User-Agent', A_READER)->get("/still-stuck/{$name}")->assertNotFound();

    expect(stillStuckOn('2026-11-03'))->toBe([]);
})->with(['follow-your-deliveries', 'a-draft', 'no-such-article']);

it('opens the email but counts nothing until going public is on', function () {
    config(['help.going_public' => false]);

    $this->withHeader('User-Agent', A_READER)->get('/still-stuck/count-stock')
        ->assertRedirect('mailto:hello@firstmilemanager.co.uk?subject=Count%20stock');

    expect(stillStuckOn('2026-11-03'))->toBe([]);
});

it('opens the email but leaves out obvious bots', function () {
    $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)')
        ->get('/still-stuck/count-stock')
        ->assertRedirect('mailto:hello@firstmilemanager.co.uk?subject=Count%20stock');

    expect(stillStuckOn('2026-11-03'))->toBe([]);
});

it('counts each UK day on its own', function () {
    $this->withHeader('User-Agent', A_READER)->get('/still-stuck/count-stock');
    $this->travelTo(CarbonImmutable::parse('2026-11-04 00:05', 'Europe/London'));
    $this->withHeader('User-Agent', A_READER)->get('/still-stuck/count-stock');
    $this->withHeader('User-Agent', A_READER)->get('/still-stuck/count-stock');

    expect(stillStuckOn('2026-11-03'))->toBe(['count-stock' => 1])
        ->and(stillStuckOn('2026-11-04'))->toBe(['count-stock' => 2]);
});

it('keeps only the Article, the day and the number, and tells the reader\'s browser nothing to keep', function () {
    $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeaders(['User-Agent' => A_READER, 'Referer' => 'https://help.firstmilemanager.co.uk/articles/count-stock'])
        ->get('/still-stuck/count-stock');

    expect(DB::table('still_stuck_counts')->get()->map(fn (object $row): array => (array) $row)->all())
        ->toBe([['article' => 'count-stock', 'day' => '2026-11-03', 'clicks' => 1]])
        ->and($response->headers->getCookies())->toBeEmpty();
    $response->assertHeader('X-Robots-Tag', 'noindex');
});
