<?php

use Illuminate\Console\Command;

it('fails the rebuild naming the file and field when header data is invalid', function (array $header, string $field, string $says) {
    $rebuild = content()
        ->article('receive-at-the-dock')
        ->article('move-a-container', $header)
        ->rebuild();

    expect($rebuild['exitCode'])->toBe(Command::FAILURE)
        ->and($rebuild['output'])->toContain("articles/move-a-container.md: {$field}: {$says}")
        ->and($rebuild['output'])->not->toContain('articles/receive-at-the-dock.md');
})->with([
    'no title' => [['title' => null], 'title', 'is missing'],
    'a title over 60 characters' => [['title' => str_repeat('a', 61)], 'title', 'must be 60 characters at most (it has 61)'],
    'no summary' => [['summary' => null], 'summary', 'is missing'],
    'a summary over 160 characters' => [['summary' => str_repeat('a', 161)], 'summary', 'must be 160 characters at most'],
    'an unknown Article Type' => [['type' => 'Tutorial'], 'type', '"Tutorial" must be one of: Getting-started, How-to, Concept, Reference, Troubleshooting, FAQ'],
    'no Product Area' => [['product_area' => null], 'product_area', 'is missing'],
    'an unknown Audience Tier' => [['audience_tier' => 'Everyone'], 'audience_tier', '"Everyone" must be one of: Staff, Client'],
    'no owner' => [['owner' => null], 'owner', 'is missing'],
    'an owner that is not a GitHub login' => [['owner' => 'AJ Jackson'], 'owner', "\"AJ Jackson\" must be the Article Owner's GitHub login"],
    'an unknown Article Status' => [['status' => 'Live'], 'status', '"Live" must be one of: Draft, Published, Retired'],
    'no Checked On' => [['checked_on' => null], 'checked_on', 'is missing'],
    'a Checked On that is not a date' => [['checked_on' => 'last week'], 'checked_on', '"last week" must be a date written YYYY-MM-DD'],
    'a Checked On that is not in the calendar' => [['checked_on' => '2026-02-30'], 'checked_on', '"2026-02-30" must be a date written YYYY-MM-DD'],
    'a misspelt field' => [['cheked_on' => '2026-10-24'], 'cheked_on', 'is not a header data field'],
]);

it('fails the rebuild for a file a writer got wrong', function (string $file, string $contents, string $problem) {
    $rebuild = content()->file($file, $contents)->rebuild();

    expect($rebuild['exitCode'])->toBe(Command::FAILURE)
        ->and($rebuild['output'])->toContain($problem);
})->with([
    'no header data' => ['articles/move-a-container.md', "# Move a container\n", 'articles/move-a-container.md: header data: the file must start with header data'],
    'header data that is not YAML' => ['articles/move-a-container.md', "---\ntitle: Move: a container: now\n---\nText\n", 'articles/move-a-container.md: header data: is not valid YAML'],
    'no body' => ['articles/move-a-container.md', "---\ntitle: Move a container\n---\n", 'articles/move-a-container.md: body: the Article has no text'],
    'an Article Name with capitals' => ['articles/Move-A-Container.md', "---\ntitle: Move a container\n---\nText\n", 'articles/Move-A-Container.md: Article Name: "Move-A-Container" must be lower-case'],
    'an Article Name with "stockist"' => ['articles/stockist-basics.md', "---\ntitle: Basics\n---\nText\n", 'articles/stockist-basics.md: Article Name: "stockist-basics" must not contain "stockist"'],
    'a file that is not Markdown' => ['articles/move-a-container.txt', 'Text', 'articles/move-a-container.txt: file: only Markdown files'],
]);

it('lists every problem in the folder at once', function () {
    $rebuild = content()
        ->article('move-a-container', ['title' => null])
        ->article('move-loose-stock', ['owner' => null, 'status' => 'Live'])
        ->rebuild();

    expect($rebuild['output'])
        ->toContain('articles/move-a-container.md: title: is missing')
        ->toContain('articles/move-loose-stock.md: owner: is missing')
        ->toContain('articles/move-loose-stock.md: status: "Live" must be one of');
});

it('leaves the published help centre as it was when a rebuild fails', function () {
    content()->article('receive-at-the-dock', ['title' => 'Receive a shipment at the dock'])->publish();

    $failed = content()
        ->article('receive-at-the-dock', ['title' => 'Receive a shipment, reworded'])
        ->article('move-a-container', ['checked_on' => 'soon'])
        ->rebuild();

    expect($failed['exitCode'])->toBe(Command::FAILURE);
    $this->get('/articles/receive-at-the-dock')->assertOk()->assertSee('Receive a shipment at the dock');
    $this->get('/articles/move-a-container')->assertNotFound();
});

it('takes the files as the only source, so an Article removed from the folder is gone', function () {
    content()->article('receive-at-the-dock')->article('move-a-container')->publish();

    content()->article('receive-at-the-dock')->publish();

    $this->get('/articles/receive-at-the-dock')->assertOk();
    $this->get('/articles/move-a-container')->assertNotFound();
});

it('says what it published', function () {
    $rebuild = content()
        ->article('receive-at-the-dock')
        ->article('move-a-container')
        ->article('count-stock', ['status' => 'Draft'])
        ->rebuild();

    expect($rebuild['exitCode'])->toBe(Command::SUCCESS)
        ->and($rebuild['output'])->toContain('Rebuilt 3 Articles')->toContain('2 published, 1 draft');
});
