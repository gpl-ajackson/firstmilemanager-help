<?php

use App\Records\Records;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BrowserLayout;
use Tests\Support\FixtureContent;
use Tests\Support\ReaderPage;

/*
| Help Previews (#59, spec #30 user stories 126 to 128): a static copy of a pull request's pages,
| written by `php artisan help:preview` into a folder that Cloudflare Pages serves. These tests
| export a fixture content folder and read the folder as Pages serves it to a reader.
*/

/**
 * The preview folders this test made: adds $new (given first back), or with none gives them all.
 *
 * @return list<string>
 */
function previewFolders(?string $new = null, bool $forget = false): array
{
    static $folders = [];
    if ($new !== null) {
        array_unshift($folders, $new);
    }
    $made = $folders;
    if ($forget) {
        $folders = [];
    }

    return $made;
}

afterEach(function () {
    foreach (previewFolders(forget: true) as $folder) {
        File::deleteDirectory($folder);
    }
});

/**
 * Exports a Help Preview of $content for pull request #$pullRequest into a new temporary folder
 * and gives the folder, failing the test if the export fails.
 */
function exportPreview(FixtureContent $content, int $pullRequest = 7): string
{
    $into = previewFolders(sys_get_temp_dir().'/help-preview-'.getmypid().'-'.Str::lower(Str::random(12)))[0];

    $exitCode = Artisan::call('help:preview', [
        '--content' => $content->path(),
        '--pictures' => $content->picturesPath(),
        '--into' => $into,
        '--pull-request' => (string) $pullRequest,
    ]);
    expect($exitCode)->toBe(0, 'The export failed: '.Artisan::output());

    return $into;
}

/**
 * The file Cloudflare Pages serves for an address, by its route matching: the file itself, else
 * <address>.html, else <address>/index.html. Null when Pages would answer page not found.
 */
function servedByPages(string $preview, string $address): ?string
{
    $path = rtrim($preview, '/').'/'.ltrim((string) parse_url($address, PHP_URL_PATH), '/');
    foreach ([$path, rtrim($path, '/').'.html', rtrim($path, '/').'/index.html'] as $file) {
        if (is_file($file)) {
            return $file;
        }
    }

    return null;
}

/** The page a reader of the preview sees at an address; fails the test when there is none. */
function previewPage(string $preview, string $address): ReaderPage
{
    $file = servedByPages($preview, $address);
    expect($file)->not->toBeNull("The preview has no page at {$address}");

    return ReaderPage::ofHtml((string) file_get_contents((string) $file));
}

it('holds the pull request\'s Published pages and its Draft Articles, each Draft marked as one', function () {
    $preview = exportPreview(content()
        ->article('receive-a-delivery', ['title' => 'Receive a delivery'])
        ->article('count-a-location', ['title' => 'Count a location', 'status' => 'Draft']));

    $published = previewPage($preview, '/articles/receive-a-delivery');
    expect($published->text('h1'))->toBe('Receive a delivery')
        ->and($published->has('.draft-marker'))->toBeFalse();

    $draft = previewPage($preview, '/articles/count-a-location');
    expect($draft->text('h1'))->toBe('Count a location')
        ->and($draft->text('.draft-marker'))->toContain('Draft');

    expect(previewPage($preview, '/')->text('h1'))->not->toBe('')
        ->and(servedByPages($preview, '/areas/inbound'))->not->toBeNull();
});

/**
 * Every page in the preview, by the address Pages serves it at.
 *
 * @return array<string, ReaderPage>
 */
function everyPreviewPage(string $preview): array
{
    $pages = [];
    foreach (File::allFiles($preview) as $file) {
        if ($file->getExtension() === 'html') {
            $address = '/'.preg_replace('/(^|\/)index$/', '', substr($file->getRelativePathname(), 0, -5));
            $pages[$address] = ReaderPage::ofHtml($file->getContents());
        }
    }
    ksort($pages);

    return $pages;
}

/** A small help centre with a page of every kind a reader can reach. */
function aPreviewedHelpCentre(): FixtureContent
{
    return content()
        ->article('receive-a-delivery', ['title' => 'Receive a delivery', 'former_addresses' => ['/articles/book-in-a-delivery']],
            "## In short\n\nScan it in.\n\n## Check the delivery {#check}\n\n![](picture:goods-in-scan-delivery)\n\nSee [](article:see-your-stock).\n")
        ->article('see-your-stock', ['title' => 'See your stock', 'audience_tier' => 'Client'])
        ->article('count-a-location', ['title' => 'Count a location', 'status' => 'Draft'])
        ->article('old-way-to-count', ['status' => 'Retired', 'what_changed' => 'Counting moved to the handheld.'])
        ->picture('goods-in-scan-delivery', ['articles' => ['receive-a-delivery']])
        ->glossaryEntry('stock-container')
        ->releaseNote('2026-11-14')
        ->withDefaultRelatedArticles();
}

it('marks every page as a Help Preview of its pull request and keeps search engines out', function () {
    $preview = exportPreview(aPreviewedHelpCentre(), pullRequest: 42);

    $pages = everyPreviewPage($preview);
    expect(array_keys($pages))->toContain('/', '/articles/receive-a-delivery', '/articles/count-a-location', '/404', '/help-preview');
    foreach ($pages as $address => $page) {
        expect($page->text('.preview-bar'))->toStartWith('Help Preview of pull request #42:', $address)
            ->and($page->attributes('meta[name=robots]'))->toBe([['name' => 'robots', 'content' => 'noindex']], $address);
    }

    // Pages sends these headers with every file it serves.
    expect(file_get_contents("{$preview}/_headers"))->toStartWith("/*\n  X-Robots-Tag: noindex, nofollow\n")
        ->and(file_get_contents("{$preview}/robots.txt"))->toBe("User-agent: *\nDisallow: /\n");
});

it('shows search and feedback, but neither sends anything anywhere, and runs no script', function () {
    $preview = exportPreview(aPreviewedHelpCentre());

    foreach (everyPreviewPage($preview) as $address => $page) {
        expect($page->has('script'))->toBeFalse("{$address} loads a script");
        foreach ($page->attributes('form') as $form) {
            expect($form)->not->toHaveKey('action', "A form on {$address} sends somewhere")
                ->and($form)->not->toHaveKey('method');
        }
        // A disabled button can't be pressed, and Enter in the box does nothing either.
        expect($page->attributes('form button'))->each(fn ($button) => $button->toHaveKey('disabled'));
        foreach ($page->links('a') as [, $href]) {
            expect($href)->not->toContain('/still-stuck/')->not->toContain('help.firstmilemanager.co.uk');
        }
    }

    expect(previewPage($preview, '/')->text('form.search'))->toContain('Search does nothing in a Help Preview');
    $article = previewPage($preview, '/articles/receive-a-delivery');
    expect($article->text('.feedback'))->toContain('Did this answer your question?')->toContain('Feedback does nothing in a Help Preview')
        ->and($article->links('.still-stuck a'))->toBe([['Email hello@firstmilemanager.co.uk', 'mailto:hello@firstmilemanager.co.uk?subject=Receive%20a%20delivery']]);
});

it('holds every style, font, Picture and icon its pages refer to', function () {
    $preview = exportPreview(aPreviewedHelpCentre());

    $needed = [];
    foreach (everyPreviewPage($preview) as $page) {
        foreach ([...$page->attributes('[href]'), ...$page->attributes('[src]')] as $attributes) {
            $needed[] = $attributes['href'] ?? $attributes['src'];
        }
        foreach ($page->attributes('[srcset]') as $attributes) {
            $needed = [...$needed, ...array_values(ReaderPage::srcset($attributes['srcset']))];
        }
    }
    foreach (File::allFiles($preview) as $file) {
        if ($file->getExtension() === 'css') {
            preg_match_all('/url\(\s*[\'"]?([^\'")\s]+)/', $file->getContents(), $found);
            $needed = [...$needed, ...$found[1]];
        }
    }
    $needed = array_unique(array_filter(
        array_map(fn (string $address): string => (string) strtok($address, '#'), $needed),
        fn (string $address): bool => str_starts_with($address, '/') && ! str_starts_with($address, '//'),
    ));

    expect($needed)->toContain('/favicon.svg')
        ->and(array_filter($needed, fn (string $address): bool => str_starts_with($address, '/pictures/')))->not->toBeEmpty()
        ->and(array_filter($needed, fn (string $address): bool => str_ends_with($address, '.woff2')))->not->toBeEmpty();
    $redirects = (string) file_get_contents("{$preview}/_redirects");
    foreach ($needed as $address) {
        $servedOrRedirected = servedByPages($preview, $address) !== null || str_contains($redirects, "{$address} ");
        expect($servedOrRedirected)->toBeTrue("Nothing is served at {$address}");
    }
});

it('keeps the help centre\'s addresses: old addresses redirect, unknown ones are page not found', function () {
    $preview = exportPreview(aPreviewedHelpCentre());

    $redirects = explode("\n", trim((string) file_get_contents("{$preview}/_redirects")));
    expect($redirects)->toContain('/articles/book-in-a-delivery /articles/receive-a-delivery 301')
        ->toContain('/privacy https://firstmilemanager.co.uk/privacy#the-help-centre 302');

    expect(ReaderPage::ofHtml((string) file_get_contents("{$preview}/404.html"))->text('h1'))->not->toBe('')
        ->and(previewPage($preview, '/articles/old-way-to-count')->text('main'))->toContain('Counting moved to the handheld.')
        ->and(previewPage($preview, '/glossary/stock-container')->text('h1'))->toBe('Stock Container')
        ->and(previewPage($preview, '/whats-new/2026-11-14')->text('h1'))->toContain('14 November 2026')
        ->and(array_column(previewPage($preview, '/for-your-clients')->links('a'), 1))->toContain('/articles/see-your-stock');
});

it('lists the Draft Articles on its own page, linked from the Help Preview bar', function () {
    $preview = exportPreview(aPreviewedHelpCentre());

    expect(previewPage($preview, '/')->links('.preview-bar a'))->toBe([['1 Draft Article', '/help-preview']]);
    $drafts = previewPage($preview, '/help-preview');
    expect($drafts->links('.drafts a'))->toBe([['Count a location Draft How-to · Inbound · Staff', '/articles/count-a-location']]);

    // The Draft is listed nowhere else: published listings stay as readers will see them.
    expect(previewPage($preview, '/areas/inbound')->texts('a'))->not->toContain('Count a location');
});

it('records nothing and leaves the help centre as it was', function () {
    config(['help.going_public' => true]);
    content()->article('what-the-help-centre-holds', ['title' => 'What the help centre holds'])->publish();

    exportPreview(aPreviewedHelpCentre());

    $today = Records::today();
    $records = app(Records::class);
    expect($records->pageViews($today, $today))->toBe([])
        ->and($records->searches($today, $today))->toBe([])
        ->and($records->votes($today, $today))->toBe([])
        ->and($records->stillStuckCounts($today, $today))->toBe([]);

    $this->get('/articles/what-the-help-centre-holds')->assertOk();
    $this->get('/articles/receive-a-delivery')->assertNotFound();
    $this->get('/articles/count-a-location')->assertNotFound();
    expect(ReaderPage::of($this->get('/'))->has('.preview-bar'))->toBeFalse();
});

it('writes nothing when the content can\'t be published, and never into a folder that holds files', function () {
    $into = previewFolders(sys_get_temp_dir().'/help-preview-'.getmypid().'-'.Str::lower(Str::random(12)))[0];

    $exitCode = Artisan::call('help:preview', ['--content' => content()->article('x', ['type' => 'Essay'])->path(), '--into' => $into]);
    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('articles/x.md: type:')
        ->and(File::isDirectory($into) ? File::allFiles($into) : [])->toBe([]);

    File::ensureDirectoryExists($into);
    File::put("{$into}/keep.txt", 'Not the preview\'s');
    expect(Artisan::call('help:preview', ['--content' => content()->path(), '--into' => $into]))->toBe(1)
        ->and(Artisan::output())->toContain("isn't empty")
        ->and(File::get("{$into}/keep.txt"))->toBe('Not the preview\'s');
});

it('fits a handheld: the Help Preview bar and the draft marker never make a page scroll sideways', function (int $width) {
    $preview = exportPreview(aPreviewedHelpCentre(), pullRequest: 1234);

    foreach (['/articles/count-a-location', '/help-preview', '/'] as $address) {
        $page = TestResponse::fromBaseResponse(response((string) file_get_contents((string) servedByPages($preview, $address))));
        $layout = BrowserLayout::of($page, width: $width, selectors: ['.preview-bar', '.site-head']);

        expect($layout->scrollWidth)->toBeLessThanOrEqual($width, "{$address} scrolls sideways at {$width}px")
            ->and($layout->box('.preview-bar')['y'])->toBeLessThan($layout->box('.site-head')['y']);
    }
})->with([320, 390, 1280]);
