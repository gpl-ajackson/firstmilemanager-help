<?php

namespace App\LaunchChecks\Indexing;

use App\Content\ArticleSource;
use App\Content\ArticleStatus;
use App\Content\ContentLibrary;
use App\Content\FactsSource;
use App\Content\InvalidContent;
use App\LaunchChecks\ArticleFile;
use App\LaunchChecks\Check;
use App\LaunchChecks\CheckContext;
use App\LaunchChecks\Findings;
use App\Site\GoingPublic;
use App\Site\Http\Controllers\RobotsTxt;
use App\Site\IndexedPages;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Launch Check 9, Indexing and facts (spec #30, user stories 12 and 136 to 138): what search
 * engines and AI assistants read is exactly what is published, and matches the marketing site.
 *
 * - The sitemap lists exactly the pages search engines may index: every page the help centre
 *   says may be indexed (App\Site\IndexedPages), every Published Article, and every page found
 *   by following links from the home that answers 200 without "noindex"; and nothing else.
 * - Search results (/search, with words and without) and each Retired Article's notice say
 *   "noindex".
 * - /llms.txt lists every Published Article, and no other.
 * - The help centre's facts (content/facts.yaml: name, description, stage and the marketing
 *   site's address) are word for word the marketing site's src/data/site.ts at main.
 *
 * The pages are read as they will be once the help centre has gone public, whether or not it
 * has: the content under test is loaded into the database in a transaction that is always
 * rolled back, and each page is asked for in this process through the help centre's own
 * routes, as a search engine would (robots.txt's closed addresses aside). So the check needs
 * the help centre's migrated database, as a deploy does, and changes nothing in it.
 */
final readonly class IndexingCheck implements Check
{
    public const string SITEMAP = 'indexing.sitemap';

    public const string NOINDEX = 'indexing.noindex';

    public const string LLMS_TXT = 'indexing.llms-txt';

    public const string FACTS = 'indexing.facts';

    /** The words the check searches for, to see a page of search results. */
    public const SEARCH_WORDS = 'stock';

    /** The most pages the crawl from the home asks for; a help centre of 76 Articles has a few hundred. */
    public const MOST_PAGES = 5000;

    public function __construct(
        private ContentLibrary $library,
        private DatabaseManager $database,
        private Kernel $kernel,
    ) {}

    public function run(CheckContext $context, Findings $findings): void
    {
        $this->pages($context, $findings);
        $this->facts($context, $findings);
    }

    private function pages(CheckContext $context, Findings $findings): void
    {
        if ($context->contentProblems() !== []) {
            $findings->notRun('Indexing and facts, the sitemap, noindex pages and llms.txt: the content folder can\'t be rebuilt (see the header failures)');

            return;
        }

        $connection = $this->database->connection();
        $connection->beginTransaction();
        try {
            $this->library->rebuild($context->contentPath, $context->picturesPath);
            GoingPublic::asIfOn(function () use ($context, $findings): void {
                $visitor = new Visitor($this->kernel);
                $mustNotIndex = $this->noindexPages($context, $visitor, $findings);
                $this->sitemap($context, $visitor, $mustNotIndex, $findings);
                $this->llmsTxt($context, $visitor, $findings);
            });
        } catch (InvalidContent) {
            $findings->notRun('Indexing and facts, the sitemap, noindex pages and llms.txt: the content folder can\'t be rebuilt (see the header failures)');
        } catch (Throwable $failure) {
            $findings->fail(self::SITEMAP, '/sitemap.xml', null,
                "the content couldn't be loaded into the help centre's database to read its pages: {$failure->getMessage()}");
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * Search results and Retired Articles' notices: each must say "noindex".
     *
     * @return array<string, true> Their addresses, which the sitemap must not list.
     */
    private function noindexPages(CheckContext $context, Visitor $visitor, Findings $findings): array
    {
        $addresses = [];
        foreach (['/search', '/search?q='.self::SEARCH_WORDS] as $address) {
            $addresses[$address] = true;
            if (! $visitor->visit($address)->saysNoindex()) {
                $findings->fail(self::NOINDEX, $address, null, 'search results must say "noindex", so search engines never index them, but this page doesn\'t');
            }
        }

        foreach ($this->articles($context, ArticleStatus::Retired) as $article) {
            $address = ArticleSource::addressOf($article->name);
            $addresses[$address] = true;
            $visit = $visitor->visit($address);
            // A Retired Article with a replacement redirects to it, which is never indexed.
            if ($visit->location === null && ! $visit->saysNoindex()) {
                $findings->fail(self::NOINDEX, $article->file, null, "a Retired Article's notice must say \"noindex\", so search engines never index it, but {$address} doesn't");
            }
        }

        return $addresses;
    }

    /**
     * The sitemap lists every page search engines may index, and only those.
     *
     * @param  array<string, true>  $mustNotIndex
     */
    private function sitemap(CheckContext $context, Visitor $visitor, array $mustNotIndex, Findings $findings): void
    {
        $sitemap = $visitor->visit('/sitemap.xml');
        $xml = $sitemap->status === 200 ? @simplexml_load_string($sitemap->body) : false;
        if ($xml === false) {
            $findings->fail(self::SITEMAP, '/sitemap.xml', null, $sitemap->status === 200 ? 'isn\'t a sitemap search engines can read' : "answers {$sitemap->status}");

            return;
        }

        $site = self::siteAddress();
        $listed = [];
        foreach ($xml->url as $url) {
            $location = trim((string) $url->loc);
            $address = self::internal($location);
            if ($address === null || ! str_starts_with($location, $site)) {
                $findings->fail(self::SITEMAP, '/sitemap.xml', null, "lists {$location}, which isn't at the help centre's address ({$site})");

                continue;
            }
            $listed[$address] = true;
            $visit = $visitor->visit($address);
            if (! $visit->isIndexable()) {
                $findings->fail(self::SITEMAP, '/sitemap.xml', null, "lists {$address}, which search engines may not index: {$visit->whyNotIndexable()}");
            }
        }

        foreach ($this->indexable($context, $visitor) as $address) {
            if (! isset($listed[$address]) && ! isset($mustNotIndex[$address])) {
                $findings->fail(self::SITEMAP, '/sitemap.xml', null, "doesn't list {$address}, which search engines may index (it answers 200 without \"noindex\")");
            }
        }
    }

    /**
     * Every page search engines may index: what the help centre says may be indexed, every
     * Published Article, and every page found from the home, each that answers 200 without
     * "noindex", in that order.
     *
     * @return list<string>
     */
    private function indexable(CheckContext $context, Visitor $visitor): array
    {
        $candidates = [];
        foreach (app(IndexedPages::class)->all() as $page) {
            $candidates[$page->address] = true;
        }
        foreach ($this->articles($context, ArticleStatus::Published) as $article) {
            $candidates[ArticleSource::addressOf($article->name)] = true;
        }
        foreach ($this->crawl($visitor) as $address) {
            $candidates[$address] = true;
        }

        return array_values(array_filter(array_map(strval(...), array_keys($candidates)), fn (string $address): bool => $visitor->visit($address)->isIndexable()));
    }

    /**
     * Every address reached by following links from the home, as a search engine would: links
     * and redirects within the help centre, apart from the addresses robots.txt closes and the
     * fingerprinted files.
     *
     * @return list<string>
     */
    private function crawl(Visitor $visitor): array
    {
        $queue = ['/'];
        $seen = ['/' => true];
        while ($queue !== [] && count($seen) <= self::MOST_PAGES) {
            $visit = $visitor->visit(array_shift($queue));
            $next = $visit->location === null ? $visit->links() : [$visit->location];
            foreach ($next as $href) {
                $address = self::internal($href);
                if ($address !== null && ! isset($seen[$address]) && self::isCrawled($address)) {
                    $seen[$address] = true;
                    $queue[] = $address;
                }
            }
        }

        return array_keys($seen);
    }

    /** llms.txt lists every Published Article, and no other. */
    private function llmsTxt(CheckContext $context, Visitor $visitor, Findings $findings): void
    {
        $llms = $visitor->visit('/llms.txt');
        if ($llms->status !== 200) {
            $findings->fail(self::LLMS_TXT, '/llms.txt', null, "answers {$llms->status}");

            return;
        }

        preg_match_all('~\]\(\s*'.preg_quote(self::siteAddress(), '~').'(/articles/[a-z0-9-]+)\s*\)~', $llms->body, $matches);
        $listed = array_flip($matches[1]);
        foreach ($context->articles() as $article) {
            $address = ArticleSource::addressOf($article->name);
            $status = $article->text('status');
            if ($status === ArticleStatus::Published->value && ! isset($listed[$address])) {
                $findings->fail(self::LLMS_TXT, $article->file, null, "/llms.txt doesn't list this Published Article ({$address})");
            } elseif ($status !== ArticleStatus::Published->value && isset($listed[$address])) {
                $findings->fail(self::LLMS_TXT, $article->file, null, "/llms.txt lists {$address}, but this Article is {$status}: only Published Articles are listed");
            }
        }
    }

    /** @return list<ArticleFile> The content's Articles with this status. */
    private function articles(CheckContext $context, ArticleStatus $status): array
    {
        return array_values(array_filter($context->articles(), fn (ArticleFile $article): bool => $article->text('status') === $status->value));
    }

    /** The help centre's address, as the sitemap and llms.txt give it (config help.address). */
    private static function siteAddress(): string
    {
        return rtrim(config()->string('help.address'), '/');
    }

    /** A link's address within the help centre ("/articles/x"), or null for a link elsewhere. */
    private static function internal(string $href): ?string
    {
        $href = (string) preg_replace('/#.*\z/s', '', trim($href));
        $site = self::siteAddress();
        if ($href === $site || str_starts_with($href, $site.'/')) {
            $href = substr($href, strlen($site));

            return $href === '' ? '/' : $href;
        }

        return str_starts_with($href, '/') && ! str_starts_with($href, '//') ? $href : null;
    }

    /** Whether a search engine would ask for the address: robots.txt doesn't close it and it is a page, not a file. */
    private static function isCrawled(string $address): bool
    {
        foreach ([...RobotsTxt::CLOSED, '/assets/', '/pictures/'] as $closed) {
            if (str_starts_with($address, $closed)) {
                return false;
            }
        }

        return true;
    }

    private function facts(CheckContext $context, Findings $findings): void
    {
        if ($context->marketingSitePath === null) {
            $findings->notRun('Indexing and facts, the facts against the marketing site\'s '.MarketingSite::SITE_TS.': give --marketing-site=<a checkout of gpl-ajackson/firstmilemanager-marketing at main>');

            return;
        }

        $problems = [];
        $ours = FactsSource::read($context->contentPath, $problems);
        if (! $ours instanceof FactsSource) {
            // The rebuild refuses the facts file, and check 1 lists why.
            return;
        }

        $theirs = (new MarketingSite($context->marketingSitePath))->facts(FactsSource::FIELDS);
        if ($theirs === null) {
            $findings->fail(self::FACTS, FactsSource::FILE, null,
                "the marketing site at {$context->marketingSitePath} has no ".MarketingSite::SITE_TS.' with `export const site = { ... }` to compare the facts with');

            return;
        }

        $file = (string) file_get_contents($context->contentPath.'/'.FactsSource::FILE);
        foreach ($ours->row() as $fact => $value) {
            $line = self::lineOf($file, $fact);
            if ($theirs[$fact] === null) {
                $findings->fail(self::FACTS, FactsSource::FILE, $line,
                    "{$fact} can't be read from the marketing site's ".MarketingSite::SITE_TS.' as one piece of quoted text, so it can\'t be compared');
            } elseif ($theirs[$fact] !== $value) {
                $findings->fail(self::FACTS, FactsSource::FILE, $line,
                    "{$fact} is \"{$value}\" here but \"{$theirs[$fact]}\" in the marketing site's ".MarketingSite::SITE_TS.': copy it from there');
            }
        }
    }

    /** The line of a YAML file where $key is set, counted from 1; null when it isn't set on a line of its own. */
    private static function lineOf(string $yaml, string $key): ?int
    {
        foreach (preg_split('/\R/', $yaml) ?: [] as $index => $line) {
            if (preg_match('/\A'.preg_quote($key, '/').'\s*:/', $line) === 1) {
                return $index + 1;
            }
        }

        return null;
    }
}
