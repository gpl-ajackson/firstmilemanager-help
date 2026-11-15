<?php

use App\Records\Records;
use Carbon\CarbonImmutable;
use Tests\Support\ReaderPage;

/*
 * The privacy notice (spec #30, Addresses and Further Note 5; user story 99): one notice shared
 * with the marketing site, whose help-centre section says what is recorded and how long it is
 * kept. /privacy on the help centre sends readers there, and every page links it.
 */

beforeEach(function () {
    content()->article('receive-at-the-dock')->publish();
});

it('redirects /privacy to the help centre\'s section of the marketing site\'s privacy notice', function () {
    $this->get('/privacy')->assertRedirect('https://firstmilemanager.co.uk/privacy#the-help-centre');
});

it('follows the marketing site\'s address in the facts', function () {
    content()->facts(['url' => 'https://www.firstmilemanager.co.uk'])->publish();

    $this->get('/privacy')->assertRedirect('https://www.firstmilemanager.co.uk/privacy#the-help-centre');
});

it('links the privacy notice from every page\'s footer', function (string $address, int $status) {
    $page = ReaderPage::of($this->get($address), status: $status);

    expect($page->links('.site-foot a'))->toContain(['What we record and how long we keep it', '/privacy']);
})->with([
    'the help home' => ['/', 200],
    'an Article' => ['/articles/receive-at-the-dock', 200],
    'page not found' => ['/articles/no-such-article', 404],
]);

it('is not counted as a page view', function () {
    config(['help.going_public' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00', 'Europe/London'));

    $this->withHeader('User-Agent', A_READER)->get('/privacy')->assertRedirect();

    expect(app(Records::class)->pageViews(CarbonImmutable::parse('2026-11-03'), CarbonImmutable::parse('2026-11-03')))->toBe([]);
});
