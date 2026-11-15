<?php

use App\Records\Records;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\Support\ReaderPage;

/*
| Going public (spec #30, user stories 133, 135 to 138 and 158; HTTP rules): one setting in this
| repository opens the help centre to search engines and starts the records, all at once. Until
| then every page says "noindex", robots.txt disallows all, the sitemap is empty and nothing is
| recorded. Search results and retired notices are never indexed either way.
*/

/** The <loc>s of a sitemap response, in order. */
function sitemapAddresses(TestResponse $response): array
{
    $sitemap = simplexml_load_string((string) $response->getContent());
    expect($sitemap)->not->toBeFalse()->and($sitemap->getName())->toBe('urlset')
        ->and($sitemap->getNamespaces())->toBe(['' => 'http://www.sitemaps.org/schemas/sitemap/0.9']);

    $addresses = [];
    foreach ($sitemap->url as $url) {
        $addresses[] = (string) $url->loc;
    }

    return $addresses;
}

it('keeps the sitemap empty until going public', function () {
    content()->article('receive-at-the-dock')->glossaryEntry('zone')->releaseNote('2026-10-30')->publish();

    $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    expect(sitemapAddresses($response))->toBe([]);
});

/**
 * A help centre with something of every kind: Articles of both tiers in two Product Areas, a
 * Draft, a Retired Article (with its notice) and a replaced one, Glossary Entries of both tiers
 * and two Release Notes.
 */
function aHelpCentreOfEveryKind(): void
{
    content()
        ->productAreas(['Warehouses' => 'warehouses.cluster.title', 'Inbound' => 'inbound.cluster.title'])
        ->article('receive-at-the-dock', ['title' => 'Receive at the dock', 'checked_on' => '2026-10-20', 'next_review_on' => '2027-04-20'], "Check each line against the [delivery](term:zone).\n")
        ->article('add-a-warehouse', ['title' => 'Add a warehouse', 'product_area' => 'Warehouses', 'type' => 'Getting-started'])
        ->article('see-your-stock', ['title' => 'See your stock', 'audience_tier' => 'Client', 'product_area' => 'Inventory'])
        ->article('count-stock', ['status' => 'Draft'])
        ->article('print-a-dock-sheet', ['status' => 'Retired', 'what_changed' => 'First Mile Manager no longer prints dock sheets.'])
        ->article('book-a-receiving-slot', ['status' => 'Retired', 'replacement' => 'receive-at-the-dock'])
        ->glossaryEntry('zone')
        ->clientGlossaryEntry('on-hold')
        ->releaseNote('2026-10-30', ['summary' => 'The help centre is open.'])
        ->releaseNote('2026-11-14', ['summary' => 'Delivery numbers now carry their warehouse.'])
        ->publish();
}

it('lists exactly the indexed pages in the sitemap once going public is on', function () {
    config(['help.going_public' => true]);
    aHelpCentreOfEveryKind();

    expect(sitemapAddresses($this->get('/sitemap.xml')->assertOk()))->toBe([
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
        'https://help.firstmilemanager.co.uk/areas/inventory',
        'https://help.firstmilemanager.co.uk/for-your-clients',
        'https://help.firstmilemanager.co.uk/troubleshooting',
        'https://help.firstmilemanager.co.uk/articles/add-a-warehouse',
        'https://help.firstmilemanager.co.uk/articles/receive-at-the-dock',
        'https://help.firstmilemanager.co.uk/articles/see-your-stock',
        'https://help.firstmilemanager.co.uk/glossary',
        'https://help.firstmilemanager.co.uk/glossary/zone',
        'https://help.firstmilemanager.co.uk/client-glossary',
        'https://help.firstmilemanager.co.uk/client-glossary/on-hold',
        'https://help.firstmilemanager.co.uk/whats-new',
        'https://help.firstmilemanager.co.uk/whats-new/2026-11-14',
        'https://help.firstmilemanager.co.uk/whats-new/2026-10-30',
    ]);
});

it('dates Articles by their Checked On and Release Notes by their release in the sitemap', function () {
    config(['help.going_public' => true]);
    aHelpCentreOfEveryKind();

    $sitemap = simplexml_load_string((string) $this->get('/sitemap.xml')->getContent());
    $lastChanged = [];
    foreach ($sitemap->url as $url) {
        $lastChanged[(string) $url->loc] = isset($url->lastmod) ? (string) $url->lastmod : null;
    }

    expect($lastChanged['https://help.firstmilemanager.co.uk/articles/receive-at-the-dock'])->toBe('2026-10-20')
        ->and($lastChanged['https://help.firstmilemanager.co.uk/whats-new/2026-11-14'])->toBe('2026-11-14')
        ->and($lastChanged['https://help.firstmilemanager.co.uk/'])->toBeNull();
});

it('lists every page a reader can reach that search engines may index, and nothing else', function () {
    config(['help.going_public' => true]);
    aHelpCentreOfEveryKind();
    $inSitemap = array_map(
        fn (string $address): string => substr($address, strlen('https://help.firstmilemanager.co.uk')),
        sitemapAddresses($this->get('/sitemap.xml')),
    );

    // Every page in the sitemap answers as an indexable page.
    foreach ($inSitemap as $address) {
        $this->get($address)->assertOk()->assertHeaderMissing('X-Robots-Tag')->assertDontSee('noindex');
    }

    // Every page a reader reaches by following links from the home is in the sitemap, unless it is
    // never indexed ("noindex", a redirect or not a page).
    $toVisit = ['/'];
    $visited = [];
    $indexable = [];
    while ($toVisit !== []) {
        $address = array_shift($toVisit);
        if (isset($visited[$address])) {
            continue;
        }
        $visited[$address] = true;
        $response = $this->get($address);
        $isPage = $response->getStatusCode() === 200 && str_starts_with((string) $response->headers->get('Content-Type'), 'text/html');
        if (! $isPage) {
            continue;
        }
        $html = (string) $response->getContent();
        if ($response->headers->get('X-Robots-Tag') === null && ! str_contains($html, '<meta name="robots" content="noindex">')) {
            $indexable[] = $address;
        }
        foreach (ReaderPage::of($response)->attributes('a[href]') as $link) {
            $href = preg_replace('/#.*\z/', '', $link['href']);
            if (str_starts_with($href, '/') && ! str_starts_with($href, '//') && ! preg_match('~\A/(assets|pictures)/~', $href)) {
                $toVisit[] = $href;
            }
        }
    }

    expect($visited)->toHaveKeys(['/search', '/articles/see-your-stock', '/client-glossary/on-hold', '/whats-new/2026-10-30'])
        ->and(array_values(array_diff($indexable, $inSitemap)))->toBe([]);
});

it('never indexes search results or retired notices, even once going public is on', function () {
    config(['help.going_public' => true]);
    aHelpCentreOfEveryKind();

    foreach (['/search', '/search?q=dock', '/articles/print-a-dock-sheet'] as $address) {
        $response = $this->get($address);
        expect($response->headers->get('X-Robots-Tag'))->toBe('noindex', $address)
            ->and($response->getContent())->toContain('<meta name="robots" content="noindex">');
    }
});

it('lets search engines in through robots.txt once going public is on, naming the sitemap', function () {
    config(['help.going_public' => true]);

    // The search results page stays open to crawlers so they see its "noindex"; the addresses
    // that record something or send the reader on are closed to them.
    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertContent(implode("\n", [
            'User-agent: *',
            'Disallow: /searches/',
            'Disallow: /suggestions',
            'Disallow: /feedback/',
            'Disallow: /still-stuck/',
            '',
            'Sitemap: https://help.firstmilemanager.co.uk/sitemap.xml',
            '',
        ]));
});

it('gives AI assistants what the help centre is, its Product Areas and every published Article in llms.txt', function () {
    aHelpCentreOfEveryKind();

    $this->get('/llms.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertContent(implode("\n", [
            '# First Mile Manager Help centre',
            '',
            '> Help for First Mile Manager, for a courier’s staff and the clients they share it with. First Mile Manager is software for small couriers and hauliers that collect from local businesses.',
            '',
            'Stage: Early access.',
            'About First Mile Manager: https://firstmilemanager.co.uk',
            '',
            '## Product Areas',
            '',
            '- [Warehouses](https://help.firstmilemanager.co.uk/areas/warehouses): 1 Article',
            '- [Inbound](https://help.firstmilemanager.co.uk/areas/inbound): 1 Article',
            '- [Inventory](https://help.firstmilemanager.co.uk/areas/inventory): 1 Article',
            '',
            '## Articles: Warehouses',
            '',
            '- [Add a warehouse](https://help.firstmilemanager.co.uk/articles/add-a-warehouse): How to do one task in First Mile Manager, in one sentence.',
            '',
            '## Articles: Inbound',
            '',
            '- [Receive at the dock](https://help.firstmilemanager.co.uk/articles/receive-at-the-dock): How to do one task in First Mile Manager, in one sentence.',
            '',
            '## Articles: Inventory',
            '',
            '- [See your stock](https://help.firstmilemanager.co.uk/articles/see-your-stock): How to do one task with your courier, in one sentence. (For clients)',
            '',
            '## Glossaries and Release Notes',
            '',
            '- [Glossary](https://help.firstmilemanager.co.uk/glossary): the words First Mile Manager uses, explained for staff',
            '- [Client Glossary](https://help.firstmilemanager.co.uk/client-glossary): the words clients meet, explained for clients',
            '- [What’s new](https://help.firstmilemanager.co.uk/whats-new): a Release Note for each release, newest first',
            '- [What’s new: 14 November 2026](https://help.firstmilemanager.co.uk/whats-new/2026-11-14): Delivery numbers now carry their warehouse.',
            '- [What’s new: 30 October 2026](https://help.firstmilemanager.co.uk/whats-new/2026-10-30): The help centre is open.',
            '',
        ]));
});

it('lists every published Article in llms.txt and no Draft or Retired one', function () {
    aHelpCentreOfEveryKind();

    $llms = (string) $this->get('/llms.txt')->getContent();

    expect($llms)->toContain('/articles/receive-at-the-dock)', '/articles/add-a-warehouse)', '/articles/see-your-stock)')
        ->not->toContain('count-stock')
        ->not->toContain('print-a-dock-sheet')
        ->not->toContain('book-a-receiving-slot');
});

it('starts every record at once when going public is on, and records nothing before', function (bool $goingPublic) {
    config(['help.going_public' => $goingPublic]);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00', 'Europe/London'));
    aHelpCentreOfEveryKind();
    $reader = ['User-Agent' => A_READER, 'Origin' => rtrim(url('/'), '/'), 'Sec-Fetch-Site' => 'same-origin'];

    $this->withHeaders($reader)->get('/articles/receive-at-the-dock')->assertOk();
    $this->withHeaders($reader)->get('/search?q=dock')->assertOk();
    $this->withHeaders($reader)->post('/feedback/receive-at-the-dock', ['answer' => 'yes'])->assertOk();
    $this->withHeaders($reader)->get('/still-stuck/receive-at-the-dock')->assertRedirect();

    $records = app(Records::class);
    $day = CarbonImmutable::parse('2026-11-03');
    $kept = [
        'Page View Counts' => $records->pageViews($day, $day),
        'Search Records' => $records->searches($day, $day),
        'Article Feedback' => $records->votes($day, $day),
        'Still Stuck Counts' => $records->stillStuckCounts($day, $day),
    ];

    foreach ($kept as $kind => $rows) {
        expect($rows === [])->toBe(! $goingPublic, $kind);
    }
    if ($goingPublic) {
        expect($kept['Page View Counts'])->toMatchArray(['/articles/receive-at-the-dock' => 1])
            ->and($kept['Still Stuck Counts'])->toBe(['receive-at-the-dock' => 1]);
    }
})->with(['going public off' => false, 'going public on' => true]);

it('says "noindex" on every page until going public', function () {
    aHelpCentreOfEveryKind();
    config(['help.going_public' => true]);
    $pages = sitemapAddresses($this->get('/sitemap.xml'));
    config(['help.going_public' => false]);

    expect($pages)->toHaveCount(24);
    foreach ($pages as $page) {
        $this->get(substr($page, strlen('https://help.firstmilemanager.co.uk')))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex')
            ->assertSee('<meta name="robots" content="noindex">', escape: false);
    }
    foreach (['/sitemap.xml', '/llms.txt', '/robots.txt'] as $notAPage) {
        $this->get($notAPage)->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
    }
});

it('goes public by one change in this repository: the literal setting in config/help.php', function () {
    // Not an environment variable, so turning it on is one reviewed pull request.
    expect(file_get_contents(base_path('config/help.php')))->toMatch("/^    'going_public' => (true|false),$/m");

    // Everything that changes with it (noindex, robots.txt, the sitemap and every record) asks
    // App\Site\GoingPublic, and nothing else reads the setting.
    $readers = [];
    foreach (['app', 'resources', 'routes', 'bootstrap', 'config'] as $folder) {
        foreach (File::allFiles(base_path($folder)) as $file) {
            if (str_contains($file->getContents(), 'going_public')) {
                $readers[] = $folder.'/'.$file->getRelativePathname();
            }
        }
    }

    expect($readers)->toEqualCanonicalizing(['app/Site/GoingPublic.php', 'config/help.php']);
});
