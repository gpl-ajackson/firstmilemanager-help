<?php

use App\Content\ContentLibrary;
use App\Site\Http\Controllers\ShowArticle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Tests\Support\FixtureContent;
use Tests\Support\LaunchCheckRun;

/*
 * Launch Check 9, Indexing and facts (spec #30, user stories 12 and 136 to 138): what search
 * engines and AI assistants read is exactly what is published, and says what the marketing site
 * says. The sitemap holds exactly the indexed pages; search results and retired notices say
 * "noindex"; llms.txt lists every Published Article; and the help centre's name, description,
 * stage and the marketing site's address match the marketing site's src/data/site.ts at main.
 */

/** A small help centre that passes every other check, so a run's failures are this check's. */
function anIndexedHelpCentre(): FixtureContent
{
    return content()
        ->productAreas(['Warehouses' => 'warehouses.cluster.title', 'Inbound' => 'inbound.cluster.title'])
        ->articleOwners(['gpl-ajackson'])
        ->article('receive-at-the-dock', ['title' => 'Receive at the dock'])
        ->article('add-a-warehouse', ['title' => 'Add a warehouse', 'product_area' => 'Warehouses'])
        ->article('count-stock', ['status' => 'Draft'])
        ->article('print-a-dock-sheet', ['status' => 'Retired', 'what_changed' => 'First Mile Manager no longer prints dock sheets.'])
        ->article('book-a-receiving-slot', ['status' => 'Retired', 'replacement' => 'receive-at-the-dock'])
        ->glossaryEntry('zone');
}

/** The Launch Checks over $content, against a main project whose GLOSSARY.md has the fixture's term. */
function indexingRun(FixtureContent $content): LaunchCheckRun
{
    return launchChecks($content)->against(mainProject()->glossary(['Zone' => 'Part of a warehouse.']));
}

it('passes a help centre whose sitemap, noindex pages, llms.txt and facts are right', function () {
    $run = indexingRun(anIndexedHelpCentre())->marketingSite(marketingSite())->run();

    expect($run->failures('indexing'))->toBe([])
        ->and($run->exitCode)->toBe(Command::SUCCESS)
        ->and($run->output)->not->toContain('Not run: Indexing');
});

it('checks the pages as they will be once public, while going public is still off, and changes nothing', function () {
    config(['help.going_public' => false]);
    content()->article('a-published-article')->publish();

    $run = indexingRun(anIndexedHelpCentre())->run();

    expect($run->failures('indexing'))->toBe([])
        ->and(config('help.going_public'))->toBeFalse()
        ->and(app(ContentLibrary::class)->publishedArticles()->pluck('name')->all())->toBe(['a-published-article']);
});

/**
 * Serves $sitemap's addresses at /sitemap.xml in place of the help centre's own, as a sitemap
 * gone wrong would.
 *
 * @param  list<string>  $addresses
 */
function aSitemapListing(array $addresses): void
{
    Route::get('/sitemap.xml', fn () => response('<?xml version="1.0" encoding="UTF-8"?>'."\n"
        .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
        .implode('', array_map(fn (string $address): string => '  <url><loc>'.htmlspecialchars($address, ENT_XML1)."</loc></url>\n", $addresses))
        ."</urlset>\n")->header('Content-Type', 'application/xml; charset=UTF-8'));
}

/** Every page of anIndexedHelpCentre() search engines may index, in full. */
const INDEXED_PAGES = [
    'https://help.firstmilemanager.co.uk/',
    'https://help.firstmilemanager.co.uk/roles/admin',
    'https://help.firstmilemanager.co.uk/roles/finance-manager',
    'https://help.firstmilemanager.co.uk/roles/account-manager',
    'https://help.firstmilemanager.co.uk/roles/transport-manager',
    'https://help.firstmilemanager.co.uk/roles/driver',
    'https://help.firstmilemanager.co.uk/roles/warehouse-manager',
    'https://help.firstmilemanager.co.uk/roles/warehouse-operative',
    'https://help.firstmilemanager.co.uk/getting-started',
    'https://help.firstmilemanager.co.uk/areas/warehouses',
    'https://help.firstmilemanager.co.uk/areas/inbound',
    'https://help.firstmilemanager.co.uk/for-your-clients',
    'https://help.firstmilemanager.co.uk/troubleshooting',
    'https://help.firstmilemanager.co.uk/articles/add-a-warehouse',
    'https://help.firstmilemanager.co.uk/articles/example-concept',
    'https://help.firstmilemanager.co.uk/articles/example-overview',
    'https://help.firstmilemanager.co.uk/articles/example-reference',
    'https://help.firstmilemanager.co.uk/articles/receive-at-the-dock',
    'https://help.firstmilemanager.co.uk/glossary',
    'https://help.firstmilemanager.co.uk/glossary/zone',
    'https://help.firstmilemanager.co.uk/client-glossary',
    'https://help.firstmilemanager.co.uk/whats-new',
];

it('fails a sitemap missing an indexed page', function () {
    aSitemapListing(array_values(array_diff(INDEXED_PAGES, [
        'https://help.firstmilemanager.co.uk/articles/add-a-warehouse',
        'https://help.firstmilemanager.co.uk/glossary/zone',
    ])));

    $run = indexingRun(anIndexedHelpCentre())->run();

    expect($run->failures('indexing'))->toBe([
        '/sitemap.xml [indexing.sitemap] doesn\'t list /articles/add-a-warehouse, which search engines may index (it answers 200 without "noindex")',
        '/sitemap.xml [indexing.sitemap] doesn\'t list /glossary/zone, which search engines may index (it answers 200 without "noindex")',
    ])->and($run->exitCode)->toBe(Command::FAILURE);
});

it('fails a page a search engine finds by following links that the sitemap doesn\'t list', function () {
    // A new kind of page, linked from an Article, that nobody added to the indexed pages.
    Route::get('/stock-calculator', fn () => response('<!doctype html><title>Stock calculator</title><p>Work out your stock.</p>'));
    $content = anIndexedHelpCentre()->article('receive-at-the-dock', ['title' => 'Receive at the dock'], "Work it out with the [stock calculator](/stock-calculator).\n");

    $run = indexingRun($content)->run();

    expect($run->failures('indexing'))->toBe([
        '/sitemap.xml [indexing.sitemap] doesn\'t list /stock-calculator, which search engines may index (it answers 200 without "noindex")',
    ]);
});

it('fails a sitemap listing a noindex page, a page that isn\'t there or another site\'s', function () {
    aSitemapListing([
        ...INDEXED_PAGES,
        'https://help.firstmilemanager.co.uk/search?q=dock',
        'https://help.firstmilemanager.co.uk/articles/print-a-dock-sheet',
        'https://help.firstmilemanager.co.uk/articles/count-stock',
        'https://firstmilemanager.co.uk/pricing',
    ]);

    $run = indexingRun(anIndexedHelpCentre())->run();

    expect($run->failures('indexing'))->toBe([
        '/sitemap.xml [indexing.sitemap] lists /search?q=dock, which search engines may not index: it says "noindex"',
        '/sitemap.xml [indexing.sitemap] lists /articles/print-a-dock-sheet, which search engines may not index: it answers 410 and says "noindex"',
        '/sitemap.xml [indexing.sitemap] lists /articles/count-stock, which search engines may not index: it answers 404',
        '/sitemap.xml [indexing.sitemap] lists https://firstmilemanager.co.uk/pricing, which isn\'t at the help centre\'s address (https://help.firstmilemanager.co.uk)',
    ]);
});

it('fails search results and retired notices that search engines may index', function () {
    Route::get('/search', fn () => response('<!doctype html><title>Search</title><p>Results</p>'));
    Route::get('/articles/{name}', fn (string $name) => $name === 'print-a-dock-sheet'
        ? response('<!doctype html><title>Retired</title><p>First Mile Manager no longer prints dock sheets.</p>', 410)
        : app()->call([app(ShowArticle::class), '__invoke'], ['name' => $name]));

    $run = indexingRun(anIndexedHelpCentre())->run();

    expect($run->failures('indexing'))->toBe([
        '/search [indexing.noindex] search results must say "noindex", so search engines never index them, but this page doesn\'t',
        '/search?q=stock [indexing.noindex] search results must say "noindex", so search engines never index them, but this page doesn\'t',
        'articles/print-a-dock-sheet.md [indexing.noindex] a Retired Article\'s notice must say "noindex", so search engines never index it, but /articles/print-a-dock-sheet doesn\'t',
    ]);
});

it('fails an llms.txt missing a Published Article or listing one that isn\'t published', function () {
    Route::get('/llms.txt', fn () => response(implode("\n", [
        '# First Mile Manager Help centre',
        '',
        '## Articles: Inbound',
        '',
        '- [Receive at the dock](https://help.firstmilemanager.co.uk/articles/receive-at-the-dock): How to do one task.',
        '- [Count stock](https://help.firstmilemanager.co.uk/articles/count-stock): How to do one task.',
        '- [Example concept](https://help.firstmilemanager.co.uk/articles/example-concept): How to do one task.',
        '- [Example overview](https://help.firstmilemanager.co.uk/articles/example-overview): How to do one task.',
        '- [Example reference](https://help.firstmilemanager.co.uk/articles/example-reference): How to do one task.',
    ]))->header('Content-Type', 'text/plain; charset=UTF-8'));

    $run = indexingRun(anIndexedHelpCentre())->run();

    expect($run->failures('indexing'))->toBe([
        'articles/add-a-warehouse.md [indexing.llms-txt] /llms.txt doesn\'t list this Published Article (/articles/add-a-warehouse)',
        'articles/count-stock.md [indexing.llms-txt] /llms.txt lists /articles/count-stock, but this Article is Draft: only Published Articles are listed',
    ]);
});

it('fails a stage or description that differs from site.ts, naming both values', function () {
    $content = anIndexedHelpCentre()->facts(['stage' => 'Beta', 'description' => 'Stockist WMS is warehouse software.']);

    $run = indexingRun($content)->marketingSite(marketingSite())->run();

    expect($run->failures('indexing'))->toBe([
        'facts.yaml:'.$content->lineOf('facts.yaml', 'description:').' [indexing.facts] description is "Stockist WMS is warehouse software." here but "'.FixtureContent::DEFAULT_FACTS['description'].'" in the marketing site\'s src/data/site.ts: copy it from there',
        'facts.yaml:'.$content->lineOf('facts.yaml', 'stage:').' [indexing.facts] stage is "Beta" here but "Early access" in the marketing site\'s src/data/site.ts: copy it from there',
    ])->and($run->exitCode)->toBe(Command::FAILURE);
});

it('fails a name or address that differs from site.ts, reading only the site\'s own facts', function () {
    $run = indexingRun(anIndexedHelpCentre())
        ->marketingSite(marketingSite()->site(['name' => 'Stockist', 'url' => 'https://www.firstmilemanager.co.uk']))
        ->run();

    expect($run->failures('indexing'))->toBe([
        'facts.yaml:1 [indexing.facts] name is "First Mile Manager" here but "Stockist" in the marketing site\'s src/data/site.ts: copy it from there',
        'facts.yaml:4 [indexing.facts] url is "https://firstmilemanager.co.uk" here but "https://www.firstmilemanager.co.uk" in the marketing site\'s src/data/site.ts: copy it from there',
    ]);
});

it('fails when site.ts doesn\'t state a fact the help centre copies', function () {
    $site = marketingSite()->file('src/data/site.ts', "export const site = {\n  name: 'First Mile Manager',\n  url: 'https://firstmilemanager.co.uk',\n  description: 'Stockist WMS is ' + 'software.',\n};\n");

    $run = indexingRun(anIndexedHelpCentre())->marketingSite($site)->run();

    expect($run->failures('indexing'))->toBe([
        'facts.yaml:2 [indexing.facts] description can\'t be read from the marketing site\'s src/data/site.ts as one piece of quoted text, so it can\'t be compared',
        'facts.yaml:3 [indexing.facts] stage can\'t be read from the marketing site\'s src/data/site.ts as one piece of quoted text, so it can\'t be compared',
    ]);
});

it('says the facts weren\'t compared without a checkout of the marketing site', function () {
    $run = indexingRun(anIndexedHelpCentre())->withoutMarketingSite()->run();

    expect($run->failures('indexing'))->toBe([])
        ->and($run->output)->toContain('Not run: Indexing and facts, the facts against the marketing site\'s src/data/site.ts: give --marketing-site=<a checkout of gpl-ajackson/firstmilemanager-marketing at main>.');
});
