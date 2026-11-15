<?php

/*
| Release Notes and the Change Summaries waiting for the next FMM Release, as the rebuild
| checks them (spec #30, Content folder; plan, "Release Notes" and S7): a Help Proposal's
| Change Summary is checked on its pull request like any other content, and never shown.
*/

it('refuses a Release Note not named by its date, with no summary or with a change missing its fields', function () {
    $rebuild = content()
        ->releaseNote('2026-11-31')
        ->file('release-notes/november.md', "---\nsummary: What changed.\n---\n\nWords.\n")
        ->releaseNote('2026-11-14', ['summary' => null, 'changes' => [['product_area' => null], ['affects_clients' => 'yes', 'text' => null]]])
        ->releaseNote('2026-11-21', ['changes' => null, 'released' => 'today'])
        ->rebuild();

    expect($rebuild['exitCode'])->toBe(1)
        ->and($rebuild['output'])
        ->toContain('release-notes/2026-11-31.md: file: a Release Note is named by its FMM Release\'s date, YYYY-MM-DD.md')
        ->toContain('release-notes/november.md: file: a Release Note is named by its FMM Release\'s date')
        ->toContain('release-notes/2026-11-14.md: summary: is missing')
        ->toContain('release-notes/2026-11-14.md: changes: item 1: product_area is missing')
        ->toContain('release-notes/2026-11-14.md: changes: item 2: affects_clients must be true (it changes what clients see) or false')
        ->toContain('release-notes/2026-11-14.md: changes: item 2: text is missing')
        ->toContain('release-notes/2026-11-21.md: released: is not a Release Note header data field')
        ->toContain('release-notes/2026-11-21.md: changes: a Release Note says what changed');
});

it('publishes a Release Note with only words, such as the first one', function () {
    content()->releaseNote('2026-10-30', ['summary' => 'The help centre is open.', 'changes' => null], "Help for every part of Stockist, in one place.\n")->publish();

    $this->get('/whats-new/2026-10-30')->assertOk()->assertSee('Help for every part of Stockist, in one place.');
});

it('refuses a Change Summary with its fields missing or wrong, naming the file', function () {
    $rebuild = content()
        ->changeSummary('stockist-215-warehouse-codes', ['product_area' => null, 'team_roles' => 'Admin', 'affects_clients' => null, 'articles' => ['Book In']])
        ->changeSummary('stockist-217-operating-rules', ['owner' => 'gpl-ajackson'], "\n")
        ->file('next-release/notes.txt', 'Some notes.')
        ->file('next-release/README.md', "# The Next Release folder\n\nNot a Change Summary.\n")
        ->rebuild();

    expect($rebuild['exitCode'])->toBe(1)
        ->and($rebuild['output'])
        ->toContain('next-release/stockist-215-warehouse-codes.md: product_area: is missing')
        ->toContain('next-release/stockist-215-warehouse-codes.md: team_roles: must be a list')
        ->toContain('next-release/stockist-215-warehouse-codes.md: affects_clients: is missing')
        ->toContain('next-release/stockist-215-warehouse-codes.md: articles: "Book In" must be an Article Name')
        ->toContain('next-release/stockist-217-operating-rules.md: owner: is not a Change Summary field')
        ->toContain('next-release/stockist-217-operating-rules.md: body: the Change Summary has no sentences')
        ->toContain('next-release/notes.txt: file: only Change Summaries')
        ->not->toContain('README.md');
});

it('publishes with Change Summaries waiting, never showing them, and says how many wait', function () {
    $rebuild = content()
        ->article('book-in-a-shipment')
        ->changeSummary('stockist-215-warehouse-codes', [], "Quokkas now carry the warehouse code.\n")
        ->releaseNote('2026-11-14')
        ->rebuild();

    expect($rebuild['exitCode'])->toBe(0)
        ->and($rebuild['output'])->toContain('Rebuilt 1 Release Note; 1 Change Summary waits for the next FMM Release.');
    $this->get('/whats-new')->assertDontSee('Quokkas');
    $this->get('/whats-new/2026-11-14')->assertDontSee('Quokkas');
});

it('lists the Articles and links of Release Notes and Change Summaries that lead nowhere', function () {
    $content = content()
        ->article('book-in-a-shipment')
        ->article('old-way', ['status' => 'Draft'])
        ->changeSummary('stockist-215-warehouse-codes', ['articles' => ['book-in-a-shipment', 'gone-missing']], "See [the dock](article:dock-receiving).\n")
        ->releaseNote('2026-11-14', ['changes' => [['articles' => ['old-way'], 'text' => 'See [the new way](article:new-way).']]]);

    $rebuild = $content->rebuild();

    expect($rebuild['exitCode'])->toBe(0)
        ->and($rebuild['output'])
        ->toContain('next-release/stockist-215-warehouse-codes.md:'.$content->lineOf('next-release/stockist-215-warehouse-codes.md', 'gone-missing').': articles "gone-missing": there is no Article named "gone-missing"')
        ->toContain('next-release/stockist-215-warehouse-codes.md:'.$content->lineOf('next-release/stockist-215-warehouse-codes.md', 'See [the dock]').': link "article:dock-receiving": there is no Article named "dock-receiving"')
        ->toContain('release-notes/2026-11-14.md:'.$content->lineOf('release-notes/2026-11-14.md', 'old-way').': articles "old-way": "old-way" is a Draft')
        ->toContain('release-notes/2026-11-14.md:'.$content->lineOf('release-notes/2026-11-14.md', 'article:new-way').': link "article:new-way": there is no Article named "new-way"')
        ->not->toContain('"book-in-a-shipment"');

    expect($this->get('/whats-new/2026-11-14')->assertOk()->getContent())->not->toContain('/articles/old-way');
});

it('refuses a former address that is a Release Note\'s address', function () {
    $rebuild = content()
        ->article('book-in-a-shipment', ['former_addresses' => ['/whats-new/2026-11-14']])
        ->releaseNote('2026-11-14')
        ->rebuild();

    expect($rebuild['exitCode'])->toBe(1)->and($rebuild['output'])->toContain('/whats-new/2026-11-14');
});
