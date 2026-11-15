<?php

use App\Content\ContentLibrary;
use Illuminate\Console\Command;

/*
| Addresses that last (ADR-0001, #40): the header data that keeps old addresses working is
| checked on every rebuild, so a mistake fails the change rather than sending readers nowhere.
*/

it('fails the rebuild when an Article\'s retirement or former addresses are written wrongly', function (array $header, string $field, string $says) {
    $rebuild = content()
        ->article('receive-at-the-dock')
        ->article('move-a-container', $header)
        ->rebuild();

    expect($rebuild['exitCode'])->toBe(Command::FAILURE)
        ->and($rebuild['output'])->toContain("articles/move-a-container.md: {$field}: {$says}");
})->with([
    'a replacement on a Published Article' => [['replacement' => 'receive-at-the-dock'], 'replacement', 'only a Retired Article has one (this Article is Published)'],
    'what changed on a Draft' => [['status' => 'Draft', 'what_changed' => 'Gone.'], 'what_changed', 'only a Retired Article has one (this Article is Draft)'],
    'a Retired Article with neither' => [['status' => 'Retired'], 'what_changed', 'is missing: a Retired Article with no replacement shows readers what changed'],
    'a Retired Article with both' => [['status' => 'Retired', 'replacement' => 'receive-at-the-dock', 'what_changed' => 'Merged.'], 'what_changed', 'readers are redirected to the replacement and never see it'],
    'what changed over 300 characters' => [['status' => 'Retired', 'what_changed' => str_repeat('a', 301)], 'what_changed', 'must be 300 characters at most'],
    'a replacement that is not an Article Name' => [['status' => 'Retired', 'replacement' => '/articles/receive-at-the-dock'], 'replacement', '"/articles/receive-at-the-dock" must be the Article Name of the Article that replaces this one'],
    'a replacement that is itself' => [['status' => 'Retired', 'replacement' => 'move-a-container'], 'replacement', "a Retired Article can't replace itself"],
    'a replacement that does not exist' => [['status' => 'Retired', 'replacement' => 'move-a-pallet'], 'replacement', 'there is no Article named "move-a-pallet"'],
    'former addresses that are not a list' => [['former_addresses' => '/articles/move-a-pallet'], 'former_addresses', 'must be a list, one "- /articles/<name>" line for each address'],
    'a former address that is not an Article address' => [['former_addresses' => ['/inbound/move-a-pallet']], 'former_addresses', '"/inbound/move-a-pallet" must be an Article\'s address, written /articles/<name>'],
    'a former address that is its own' => [['former_addresses' => ['/articles/move-a-container']], 'former_addresses', '"/articles/move-a-container" is this Article\'s own address'],
    'a former address that is another Article\'s' => [['former_addresses' => ['/articles/receive-at-the-dock']], 'former_addresses', '"/articles/receive-at-the-dock" is in use by articles/receive-at-the-dock.md, and an address in use never redirects'],
]);

it('fails the rebuild when a replacement is not a Published Article', function (string $status, array $extra) {
    $rebuild = content()
        ->article('move-a-pallet', ['status' => $status, ...$extra])
        ->article('move-a-container', ['status' => 'Retired', 'replacement' => 'move-a-pallet'])
        ->rebuild();

    expect($rebuild['exitCode'])->toBe(Command::FAILURE)
        ->and($rebuild['output'])->toContain("articles/move-a-container.md: replacement: \"move-a-pallet\" is {$status}; name a Published Article");
})->with([
    'a Draft' => ['Draft', []],
    'Retired' => ['Retired', ['what_changed' => 'Pallets are moved as containers now.']],
]);

it('fails the rebuild when a Client-tier Article is replaced by a Staff one', function () {
    $rebuild = content()
        ->article('track-your-deliveries', ['audience_tier' => 'Client', 'status' => 'Retired', 'replacement' => 'book-in-a-delivery'])
        ->article('book-in-a-delivery')
        ->rebuild();

    expect($rebuild['output'])->toContain('articles/track-your-deliveries.md: replacement: "book-in-a-delivery" is for Staff; a Client-tier Article is replaced only by a Client-tier Article');
});

it('fails the rebuild when two Articles claim the same former address', function () {
    $rebuild = content()
        ->article('book-in-a-delivery', ['former_addresses' => ['/articles/book-a-slot']])
        ->article('receive-at-the-dock', ['former_addresses' => ['/articles/book-a-slot']])
        ->rebuild();

    expect($rebuild['exitCode'])->toBe(Command::FAILURE)
        ->and($rebuild['output'])->toContain('articles/receive-at-the-dock.md: former_addresses: "/articles/book-a-slot" already redirects to /articles/book-in-a-delivery (articles/book-in-a-delivery.md)');
});

it('keeps a Retired Article\'s address from ever being reused as a former address', function () {
    $rebuild = content()
        ->article('print-a-dock-sheet', ['status' => 'Retired', 'what_changed' => 'Dock sheets are no longer printed.'])
        ->article('receive-at-the-dock', ['former_addresses' => ['/articles/print-a-dock-sheet']])
        ->rebuild();

    expect($rebuild['output'])->toContain('articles/receive-at-the-dock.md: former_addresses: "/articles/print-a-dock-sheet" is in use by articles/print-a-dock-sheet.md');
});

it('lists only Published Articles, never a Draft or Retired one', function () {
    content()
        ->article('receive-at-the-dock', ['title' => 'Receive a shipment at the dock'])
        ->article('count-and-correct-stock', ['status' => 'Draft'])
        ->article('print-a-dock-sheet', ['status' => 'Retired', 'what_changed' => 'Dock sheets are no longer printed.'])
        ->article('book-a-receiving-slot', ['status' => 'Retired', 'replacement' => 'book-in-a-delivery'])
        ->article('book-in-a-delivery', ['title' => 'Book in a delivery'])
        ->publish();

    expect(app(ContentLibrary::class)->publishedArticles()->pluck('title')->all())
        ->toBe(['Book in a delivery', 'Receive a shipment at the dock']);
});
