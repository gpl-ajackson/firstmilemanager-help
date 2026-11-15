<?php

/*
 * Spec #30, HTTP rules: public pages set no cookie, every response sends the marketing site's
 * security headers, and until going public every page and robots.txt say "noindex".
 */

dataset('public responses', [
    'an Article' => ['/articles/receive-at-the-dock', 200],
    'page not found' => ['/articles/no-such-article', 404],
    'a Draft Article' => ['/articles/count-stock', 404],
    'robots.txt' => ['/robots.txt', 200],
    'the Glossary' => ['/glossary', 200],
    'a Glossary Entry' => ['/glossary/zone', 200],
    'the Client Glossary' => ['/client-glossary', 200],
    'a Client Glossary entry' => ['/client-glossary/on-hold', 200],
    'the privacy notice link' => ['/privacy', 302],
]);

beforeEach(function () {
    content()
        ->article('receive-at-the-dock')
        ->article('count-stock', ['status' => 'Draft'])
        ->glossaryEntry('zone')
        ->clientGlossaryEntry('on-hold')
        ->publish();
});

it('sets no cookie', function (string $address, int $status) {
    $response = $this->get($address)->assertStatus($status);

    expect($response->headers->has('Set-Cookie'))->toBeFalse()
        ->and($response->headers->getCookies())->toBeEmpty();
})->with('public responses');

it('sends the marketing site\'s security headers', function (string $address, int $status) {
    $this->get($address)
        ->assertStatus($status)
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
})->with('public responses');

it('says noindex on every response until going public', function (string $address, int $status) {
    $this->get($address)->assertStatus($status)->assertHeader('X-Robots-Tag', 'noindex');
})->with('public responses');

it('carries a noindex robots tag on every page until going public', function (string $address, int $status) {
    $this->get($address)->assertStatus($status)->assertSee('<meta name="robots" content="noindex">', escape: false);
})->with([
    'an Article' => ['/articles/receive-at-the-dock', 200],
    'page not found' => ['/no/such/page', 404],
]);

it('disallows everything in robots.txt until going public', function () {
    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertContent("User-agent: *\nDisallow: /\n");
});

it('drops noindex once going public is on', function () {
    config(['help.going_public' => true]);

    $this->get('/articles/receive-at-the-dock')
        ->assertOk()
        ->assertHeaderMissing('X-Robots-Tag')
        ->assertDontSee('noindex');
    $this->get('/robots.txt')->assertDontSee('Disallow: /'."\n", escape: false);
});

it('ships with going public on', function () {
    expect(require base_path('config/help.php'))->toMatchArray(['going_public' => true]);
});
