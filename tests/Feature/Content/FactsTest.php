<?php

use Symfony\Component\Yaml\Yaml;
use Tests\Support\ReaderPage;

/*
| The help centre's facts (spec #30, "Content folder"; user stories 12 to 14): First Mile Manager's name,
| description, stage and address, copied word for word from the marketing site's site.ts into
| content/facts.yaml, so the two sites say the same thing.
*/

it('links About First Mile Manager to the marketing site, from the facts', function () {
    content()->facts(['stage' => 'Early access', 'url' => 'https://firstmilemanager.co.uk'])->publish();

    $page = ReaderPage::of($this->get('/'));

    expect($page->links('.site-head a.about'))->toBe([['About First Mile Manager', 'https://firstmilemanager.co.uk']])
        ->and($page->links('.site-head a.logo-link'))->toBe([['Help centre', '/']])
        ->and($page->has('.site-head img[alt="First Mile Manager"]'))->toBeTrue();
});

it('says on every page that First Mile Manager is in early access, linking to the early access page', function (string $address) {
    content()->article('receive-at-the-dock')->glossaryEntry('location')->facts(['stage' => 'Early access', 'url' => 'https://firstmilemanager.co.uk'])->publish();

    $page = ReaderPage::of($this->get($address));

    expect($page->text('.early-access'))->toBe('First Mile Manager is in early access. Ask for early access')
        ->and($page->links('.early-access a'))->toBe([['Ask for early access', 'https://firstmilemanager.co.uk/early-access']]);
})->with(['/', '/articles/receive-at-the-dock', '/glossary/location', '/search?q=dock']);

it('takes the early access banner down once the stage moves on', function () {
    content()->facts(['stage' => 'Alpha'])->publish();

    expect(ReaderPage::of($this->get('/'))->has('.early-access'))->toBeFalse();
});

it('says what First Mile Manager is in the footer, in the marketing site\'s words', function () {
    content()->facts(['description' => 'First Mile Manager is software for small couriers.'])->publish();

    expect(ReaderPage::of($this->get('/'))->text('.site-foot'))->toContain('First Mile Manager is software for small couriers.');
});

it('refuses a content folder without its facts', function () {
    $content = content();
    unlink($content->path().'/facts.yaml');

    ['exitCode' => $exitCode, 'output' => $output] = $content->rebuild();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('facts.yaml: file: the content folder has no facts file');
});

it('refuses facts that are missing, unknown or not an address', function (array $facts, string $problem) {
    ['exitCode' => $exitCode, 'output' => $output] = content()->facts($facts)->rebuild();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain($problem);
})->with([
    'no stage' => [['stage' => null], 'facts.yaml: stage: is missing'],
    'empty name' => [['name' => ''], 'facts.yaml: name: is missing'],
    'a typo' => [['stagee' => 'Early access'], 'facts.yaml: stagee: is not a fact the help centre knows'],
    'not https' => [['url' => 'firstmilemanager.co.uk'], 'facts.yaml: url: must be the marketing site\'s https:// address'],
    'not text' => [['description' => ['a', 'list']], 'facts.yaml: description: must be text on one line'],
]);

it('keeps the real content folder\'s facts the same as the marketing site\'s', function () {
    $facts = Yaml::parseFile(base_path('content/facts.yaml'));

    // Copied from the marketing site's src/data/site.ts; Launch Check 9 compares the two.
    expect($facts)->toBe([
        'name' => 'First Mile Manager',
        'description' => 'First Mile Manager is software for small couriers and hauliers that collect from local businesses. It takes your clients\' bookings, plans your collection rounds and bills each client at the right rate.',
        'stage' => 'Early access',
        'url' => 'https://firstmilemanager.co.uk',
    ]);
});
