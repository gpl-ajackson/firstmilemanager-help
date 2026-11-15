<?php

namespace App\LaunchChecks\Pages;

use App\Content\Article;
use App\Content\AudienceTier;
use App\Content\ContentLibrary;
use App\Content\InvalidContent;
use App\LaunchChecks\Accessible\AccessibleCheck;
use App\LaunchChecks\CheckContext;
use App\LaunchChecks\Fast\FastCheck;
use App\LaunchChecks\Privacy\PrivacyCheck;
use App\Records\Records;
use App\Records\SearchRecord;
use App\Search\SearchService;
use App\Search\SearchUnavailable;
use App\Site\GoingPublic;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The page checks' one visit to the help centre (Launch Checks 7, 8 and 11; spec #30, seam 2
 * driving seam 1's browser): the content under test is loaded into the help centre's database
 * in a transaction that is always rolled back and indexed into a fresh Meilisearch index that is
 * deleted afterwards, as the Findability check does; then headless Chromium visits every
 * published page through the app itself (InProcessSite), a test reader searches, votes and asks
 * "Still stuck?" with recording on, and each page template is timed on the slow 4G profile.
 * With --speed-at, the same templates are timed at the real address too.
 *
 * It changes nothing: not the database, not the live index.
 *
 * @phpstan-import-type Scan from PagesRun
 * @phpstan-import-type Timing from PagesRun
 * @phpstan-import-type Privacy from PagesRun
 * @phpstan-import-type Kept from PagesRun
 */
final readonly class PageChecks
{
    /** A reader's browser on a warehouse handheld, not headless Chromium's own (which counts as a bot). */
    public const READER = 'Mozilla/5.0 (Linux; Android 14; TC58) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';

    public function __construct(
        private Application $app,
        private Kernel $kernel,
        private DatabaseManager $database,
        private ContentLibrary $library,
        private SearchService $search,
        private PublishedPages $published,
        private Browser $browser,
        private Records $records,
    ) {}

    public function run(CheckContext $context): PagesRun
    {
        if (! $context->checkPages && $context->speedAt === null) {
            return new PagesRun;
        }

        $connection = $this->database->connection();
        $connection->beginTransaction();
        try {
            $this->library->rebuild($context->contentPath, $context->picturesPath);
            $pages = $this->published->of($context);
            [$scans, $privacy, $timings] = $context->checkPages
                ? $this->search->inFreshIndex(fn (SearchService $search): array => $this->visit($pages, $search))
                : [[], null, []];
        } catch (InvalidContent) {
            return PagesRun::couldNot('the content folder can\'t be rebuilt, so its pages can\'t be opened (see the header failures)');
        } catch (SearchUnavailable $unavailable) {
            return PagesRun::couldNot("search can't be used, so the pages can't be opened as a deploy serves them: {$unavailable->getMessage()}");
        } catch (BrowserFailed $failed) {
            return PagesRun::couldNot("{$failed->getMessage()}. Install it with npm ci --prefix tools/diagrams and npx --prefix tools/diagrams playwright install chromium");
        } catch (Throwable $failure) {
            return PagesRun::couldNot("the content couldn't be loaded into the help centre's database to open its pages: {$failure->getMessage()}");
        } finally {
            $connection->rollBack();
        }

        [$realTimings, $realProblem] = $context->speedAt === null ? [[], null] : $this->timeAtTheRealAddress($context->speedAt, $pages, $scans);

        return new PagesRun(
            pages: $pages,
            scans: $scans,
            privacy: $privacy,
            timings: $timings,
            realAddress: $context->speedAt,
            realTimings: $realTimings,
            realProblem: $realProblem,
        );
    }

    /**
     * Every page, the test reader and the templates' times, with the app answering the browser
     * from the content under test and searching the fresh index.
     *
     * @param  list<Page>  $pages
     * @return array{0: list<Scan>, 1: Privacy|null, 2: list<Timing>}
     */
    private function visit(array $pages, SearchService $search): array
    {
        $this->app->instance(SearchService::class, $search);
        $site = new InProcessSite($this->app, $this->kernel);
        try {
            /** @var array{pages: list<Scan>} $scan */
            $scan = $this->browser->run([
                'mode' => 'scan',
                'origin' => InProcessSite::ORIGIN,
                'userAgent' => self::READER,
                'pages' => array_map(fn (Page $page): string => $page->address, $pages),
                'widths' => AccessibleCheck::WIDTHS,
                'schemes' => AccessibleCheck::SCHEMES,
                'tags' => AccessibleCheck::AXE_TAGS,
                'colourRules' => AccessibleCheck::COLOUR_RULES,
                'control' => AccessibleCheck::CONTROL_PX,
                'searchBox' => AccessibleCheck::SEARCH_BOX_PX,
            ], $site);

            $privacy = $this->testReader();

            /** @var array{timed: list<Timing>} $speed */
            $speed = $this->browser->run([
                'mode' => 'speed',
                'origin' => InProcessSite::ORIGIN,
                'userAgent' => self::READER,
                'pages' => self::heaviest($pages, $scan['pages']),
                'profile' => FastCheck::PROFILE,
            ], $site);

            return [$scan['pages'], $privacy, $speed['timed']];
        } finally {
            $this->app->forgetInstance(SearchService::class);
            $site->close();
        }
    }

    /**
     * Launch Check 8's test reader, with recording on as once the help centre is public: a
     * Search with an email address, phone number and postcode (opening the first result), a No
     * vote with a reason, and "Still stuck?". Then every row the help centre wrote is read for
     * anything about the reader: the browser, the network address, forwarded addresses, the
     * language, the page it came from, the time of day.
     *
     * @return Privacy
     */
    private function testReader(): array
    {
        $token = (string) random_int(1_000_000_000, 9_999_999_999);
        $address = '203.0.113.'.random_int(1, 254);
        $forwarded = '198.51.100.'.random_int(1, 254);
        $language = "en-GB-x-r{$token}";
        $userAgent = self::READER." reader-{$token}";
        $details = [
            'user agent' => "reader-{$token}",
            'network address' => $address,
            'forwarded address' => $forwarded,
            'language' => $language,
            'referring page' => InProcessSite::HOST,
        ];

        $articles = $this->library->publishedArticles()->sortBy('name')->values();
        $vote = $articles->first();
        $staff = $articles->first(fn (Article $article): bool => $article->audience_tier === AudienceTier::Staff);

        $today = Records::today();
        $searchesBefore = $this->searchWords($this->records->searches($today, $today));
        $votesBefore = count($this->records->votes($today, $today));
        $stillStuckBefore = array_sum($this->records->stillStuckCounts($today, $today));
        $before = $this->everyRow();

        $site = new InProcessSite($this->app, $this->kernel, readerAddress: $address);
        try {
            /** @var array{did: list<string>, kept: Kept} $visit */
            $visit = GoingPublic::asIfOn(fn (): array => $this->browser->run([
                'mode' => 'privacy',
                'origin' => InProcessSite::ORIGIN,
                'userAgent' => $userAgent,
                'headers' => ['Accept-Language' => $language, 'X-Forwarded-For' => $forwarded],
                'words' => PrivacyCheck::TEST_SEARCH,
                'vote' => $vote === null ? null : "/articles/{$vote->name}",
                'stillStuck' => $staff === null ? null : ['article' => "/articles/{$staff->name}", 'address' => "/still-stuck/{$staff->name}"],
            ], $site));
        } finally {
            $site->close();
        }

        $leaks = [];
        foreach ($this->newRows($before, $this->everyRow()) as $table => $rows) {
            foreach ($rows as $row) {
                foreach ($details as $what => $detail) {
                    if (str_contains($row, $detail)) {
                        $leaks[] = "{$table} keeps the reader's {$what}";
                    }
                }
                if (preg_match('/(?<![\d:])([01]\d|2[0-3]):[0-5]\d(?![\d])/', $row) === 1) {
                    $leaks[] = "{$table} keeps the time of day";
                }
            }
        }

        return [
            'did' => $visit['did'],
            'kept' => $visit['kept'],
            'typed' => PrivacyCheck::TEST_SEARCH,
            'searches' => self::added($searchesBefore, $this->searchWords($this->records->searches($today, $today))),
            'votes' => count($this->records->votes($today, $today)) - $votesBefore,
            'stillStuck' => array_sum($this->records->stillStuckCounts($today, $today)) - $stillStuckBefore,
            'expected' => array_values(array_filter([$vote === null ? null : 'vote', $staff === null ? null : 'still stuck'])),
            'leaks' => array_values(array_unique($leaks)),
        ];
    }

    /**
     * @param  list<SearchRecord>  $searches
     * @return list<string>
     */
    private function searchWords(array $searches): array
    {
        return array_map(fn (SearchRecord $search): string => $search->words, $searches);
    }

    /**
     * The words in $after that weren't in $before (each kept once per time it was added).
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return list<string>
     */
    private static function added(array $before, array $after): array
    {
        foreach ($before as $words) {
            $at = array_search($words, $after, true);
            if ($at !== false) {
                unset($after[$at]);
            }
        }

        return array_values($after);
    }

    /**
     * Every row of every table but migrations, each as JSON, by table.
     *
     * @return array<string, list<string>>
     */
    private function everyRow(): array
    {
        $rows = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if ($table === 'migrations') {
                continue;
            }
            $rows[$table] = array_values(array_map(
                fn (mixed $row): string => (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $this->database->connection()->table($table)->get()->all(),
            ));
        }

        return $rows;
    }

    /**
     * The rows written or changed between two readings, by table.
     *
     * @param  array<string, list<string>>  $before
     * @param  array<string, list<string>>  $after
     * @return array<string, list<string>>
     */
    private function newRows(array $before, array $after): array
    {
        $new = [];
        foreach ($after as $table => $rows) {
            $added = self::added($before[$table] ?? [], $rows);
            if ($added !== []) {
                $new[$table] = $added;
            }
        }

        return $new;
    }

    /**
     * Every page drawn with a template Launch Check 11 times.
     *
     * @param  list<Page>  $pages
     * @return list<array{address: string, template: string}>
     */
    private static function timed(array $pages): array
    {
        $timed = [];
        foreach ($pages as $page) {
            if ($page->template !== null) {
                $timed[] = ['address' => $page->address, 'template' => $page->template];
            }
        }

        return $timed;
    }

    /**
     * Each template's heaviest page: the one that loaded the most bytes when it was scanned.
     *
     * @param  list<Page>  $pages
     * @param  list<Scan>  $scans
     * @return list<array{address: string, template: string}>
     */
    private static function heaviest(array $pages, array $scans): array
    {
        $bytes = array_column($scans, 'bytes', 'address');
        $heaviest = [];
        foreach (self::timed($pages) as $page) {
            $best = $heaviest[$page['template']] ?? null;
            if ($best === null || ($bytes[$page['address']] ?? 0) > ($bytes[$best['address']] ?? 0)) {
                $heaviest[$page['template']] = $page;
            }
        }

        return array_values($heaviest);
    }

    /**
     * The templates timed at the real address, through Cloudflare Access with the service token
     * (CF_ACCESS_CLIENT_ID and CF_ACCESS_CLIENT_SECRET) while the help centre is behind it.
     *
     * The same pages are timed as here when the pages were scanned; otherwise each template's
     * heaviest page is found at the real address first.
     *
     * @param  list<Page>  $pages
     * @param  list<Scan>  $scans
     * @return array{0: list<Timing>, 1: string|null}
     */
    private function timeAtTheRealAddress(string $address, array $pages, array $scans): array
    {
        $id = Config::get('help.access.client_id');
        $secret = Config::get('help.access.client_secret');
        $headers = is_string($id) && $id !== '' && is_string($secret) && $secret !== ''
            ? ['CF-Access-Client-Id' => $id, 'CF-Access-Client-Secret' => $secret]
            : [];

        try {
            /** @var array{timed: list<Timing>} $speed */
            $speed = $this->browser->run([
                'mode' => 'speed',
                'origin' => rtrim($address, '/'),
                'userAgent' => self::READER,
                'headers' => $headers,
                'pages' => $scans === [] ? self::timed($pages) : self::heaviest($pages, $scans),
                'weigh' => $scans === [],
                'profile' => FastCheck::PROFILE,
            ]);
        } catch (BrowserFailed $failed) {
            return [[], $failed->getMessage()];
        }

        return [$speed['timed'], null];
    }
}
