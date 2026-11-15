<?php

it('gives a clear page not found with a search box for an address that never existed', function (string $address) {
    content()->article('receive-at-the-dock')->publish();

    $this->get($address)
        ->assertNotFound()
        ->assertSee('<h1>Page not found</h1>', escape: false)
        ->assertSee('<form class="search" role="search" action="/search" method="get">', escape: false)
        ->assertSee('<input id="not-found-search" name="q" type="search"', escape: false);
})->with([
    'an unknown Article' => '/articles/no-such-article',
    'an Article Name in capitals' => '/articles/Receive-At-The-Dock',
    'an unknown address' => '/no/such/page',
]);
