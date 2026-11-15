<?php

use Tests\Support\ReaderPage;

/*
 * When Meilisearch can't be reached, a deploy's rebuild fails and changes nothing, and the
 * search page says so and offers the other ways on. Search never quietly falls back.
 */

it('fails the rebuild and changes nothing when Meilisearch can\'t be reached', function () {
    content()->article('receive-a-delivery', ['title' => 'Receive a delivery'])->publish();
    config(['help.search.host' => 'http://127.0.0.1:9']);

    $rebuild = content()->article('count-stock', ['title' => 'Count stock'])->rebuild();

    expect($rebuild['exitCode'])->toBe(1)
        ->and($rebuild['output'])->toContain("The search index couldn't be rebuilt, so nothing was changed")
        ->toContain('Meilisearch at http://127.0.0.1:9');
    $this->get('/articles/receive-a-delivery')->assertOk();
    $this->get('/articles/count-stock')->assertNotFound();
});

it('says search isn\'t working, and offers the other ways on, when Meilisearch can\'t be reached', function () {
    content()->article('receive-a-delivery', ['product_area' => 'Inbound'])->publish();
    config(['help.search.host' => 'http://127.0.0.1:9']);

    $response = $this->get('/search?q=delivery')->assertHeader('X-Robots-Tag', 'noindex');
    $page = ReaderPage::of($response, status: 503);

    expect($page->text('.search-none h2'))->toBe("Search isn't working right now")
        ->and(array_column($page->links('.search-none a'), 1))->toBe(['/troubleshooting', '/glossary', '/areas/inbound']);
});
