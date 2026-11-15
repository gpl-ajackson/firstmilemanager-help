<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FixtureContent;
use Tests\Support\ReaderPage;

/*
| Assembling a Release Note from the Next Release folder (spec #30, Release tooling; user
| stories 144, 146 and 147; ADR-0003): the Change Summaries the Help Proposals left become the
| FMM Release's Release Note, grouped by Product Area with "Affects your clients" marked,
| and the folder is emptied in the same change, so nothing is announced twice.
*/

/**
 * @return array{exitCode: int, output: string}
 */
function assembleReleaseNote(FixtureContent $content, array $options = ['--date' => '2026-11-14']): array
{
    $exitCode = Artisan::call('help:release-note', ['--content' => $content->path(), ...$options]);

    return ['exitCode' => $exitCode, 'output' => Artisan::output()];
}

/** @return list<string> The files in a folder of the fixture content, by name. */
function filesIn(FixtureContent $content, string $folder): array
{
    return array_values(array_map('basename', glob($content->path().'/'.$folder.'/*') ?: []));
}

it('assembles the Release Note from the Change Summaries, grouped by Product Area with client-affecting lines marked, and empties the folder', function () {
    $content = content()
        ->productAreas(['Team' => 'team.cluster.title', 'Warehouses' => 'warehouses.cluster.title', 'Inbound' => 'inbound.cluster.title'])
        ->article('book-in-a-shipment', ['title' => 'Book in a shipment'])
        ->article('add-a-warehouse-and-zones', ['title' => 'Add a warehouse and its zones', 'product_area' => 'Warehouses'])
        ->file('next-release/README.md', "# The Next Release folder\n\nOne Change Summary per file.\n")
        ->changeSummary('stockist-215-delivery-numbers', ['product_area' => 'Inbound', 'team_roles' => ['Admin', 'Warehouse Operative'], 'affects_clients' => true, 'articles' => ['book-in-a-shipment']],
            "Delivery numbers now carry the warehouse they belong to, such as `INB-W1-000023`.\n")
        ->changeSummary('stockist-215-warehouse-codes', ['product_area' => 'Warehouses', 'articles' => ['add-a-warehouse-and-zones']],
            "Each warehouse has a Warehouse Code: 1 to 4 capital letters or digits.\n")
        ->changeSummary('stockist-220-dock-refuses', ['product_area' => 'Inbound', 'team_roles' => ['Warehouse Operative']],
            "The dock receiving screen refuses a [delivery number](article:book-in-a-shipment).\nScan the parcel's label instead.\n");

    $run = assembleReleaseNote($content);

    expect($run['exitCode'])->toBe(0)
        ->and($run['output'])->toContain('Wrote release-notes/2026-11-14.md from 3 Change Summaries (Warehouses, Inbound)')
        ->and(filesIn($content, 'release-notes'))->toBe(['2026-11-14.md'])
        ->and(filesIn($content, 'next-release'))->toBe(['README.md']);

    // What readers see once the change that holds it is merged and deployed.
    $content->publish();
    $page = ReaderPage::of($this->get('/whats-new/2026-11-14'));

    expect($page->text('h1'))->toBe('What’s new: 14 November 2026')
        ->and($page->text('.release-note .summary'))->toBe('What changed in Warehouses and Inbound.')
        ->and($page->texts('.release-area h2'))->toBe(['Warehouses', 'Inbound'])
        ->and($page->texts('.release-area .change'))->toBe([
            'Each warehouse has a Warehouse Code: 1 to 4 capital letters or digits. For: Admin Read: Add a warehouse and its zones',
            'Delivery numbers now carry the warehouse they belong to, such as INB-W1-000023. For: Admin, Warehouse Operative Affects your clients Read: Book in a shipment',
            'The dock receiving screen refuses a delivery number. Scan the parcel\'s label instead. For: Warehouse Operative',
        ])
        ->and($page->links('.release-area .change a'))->toBe([
            ['Add a warehouse and its zones', '/articles/add-a-warehouse-and-zones'],
            ['Book in a shipment', '/articles/book-in-a-shipment'],
            ['delivery number', '/articles/book-in-a-shipment'],
        ])
        ->and(ReaderPage::of($this->get('/whats-new'))->links('.release-notes a'))->toBe([
            ['What changed in Warehouses and Inbound. 14 November 2026 Affects your clients', '/whats-new/2026-11-14'],
        ]);
});

it('says there is nothing to release and writes nothing when the folder has no Change Summaries', function (bool $withGuide) {
    $content = content()->article('book-in-a-shipment');
    if ($withGuide) {
        $content->file('next-release/README.md', "# The Next Release folder\n");
    }

    $run = assembleReleaseNote($content);

    expect($run['exitCode'])->toBe(0)
        ->and($run['output'])->toContain('There is nothing to release: the Next Release folder has no Change Summaries. Nothing was written.')
        ->and(is_dir($content->path().'/release-notes'))->toBeFalse()
        ->and(filesIn($content, 'next-release'))->toBe($withGuide ? ['README.md'] : []);
})->with(['an empty folder with its guide' => true, 'no folder at all' => false]);

it('refuses Change Summaries the rebuild would refuse, writing and removing nothing', function () {
    $content = content()
        ->changeSummary('stockist-215-warehouse-codes', ['affects_clients' => 'maybe'])
        ->changeSummary('stockist-220-zone-walk-order');

    $run = assembleReleaseNote($content);

    expect($run['exitCode'])->toBe(1)
        ->and($run['output'])->toContain('next-release/stockist-215-warehouse-codes.md: affects_clients: must be true')
        ->and($run['output'])->toContain('Nothing was written')
        ->and(is_dir($content->path().'/release-notes'))->toBeFalse()
        ->and(filesIn($content, 'next-release'))->toBe(['stockist-215-warehouse-codes.md', 'stockist-220-zone-walk-order.md']);
});

it('never replaces a Release Note already written for that date', function () {
    $content = content()
        ->releaseNote('2026-11-14', ['summary' => 'The first release that day.'])
        ->changeSummary('stockist-220-zone-walk-order');

    $run = assembleReleaseNote($content);

    expect($run['exitCode'])->toBe(1)
        ->and($run['output'])->toContain('release-notes/2026-11-14.md already exists')
        ->and((string) file_get_contents($content->path().'/release-notes/2026-11-14.md'))->toContain('The first release that day.')
        ->and(filesIn($content, 'next-release'))->toBe(['stockist-220-zone-walk-order.md']);
});

it('dates the Release Note today in the UK unless told the release\'s date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 00:30', 'Europe/London'));
    $content = content()->changeSummary('stockist-220-zone-walk-order');

    $run = assembleReleaseNote($content, []);

    expect($run['exitCode'])->toBe(0)
        ->and(filesIn($content, 'release-notes'))->toBe(['2026-11-15.md']);
});

it('refuses a date that isn\'t one', function () {
    $content = content()->changeSummary('stockist-220-zone-walk-order');

    $run = assembleReleaseNote($content, ['--date' => '2026-11-31']);

    expect($run['exitCode'])->toBe(1)
        ->and($run['output'])->toContain('"2026-11-31" is not a date written YYYY-MM-DD')
        ->and(filesIn($content, 'next-release'))->toBe(['stockist-220-zone-walk-order.md']);
});
