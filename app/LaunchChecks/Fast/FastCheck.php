<?php

namespace App\LaunchChecks\Fast;

use App\Content\ArticleType;
use App\LaunchChecks\Check;
use App\LaunchChecks\CheckContext;
use App\LaunchChecks\Findings;
use App\LaunchChecks\Pages\Page;
use App\LaunchChecks\Pages\PagesRun;

/**
 * Launch Check 11, Fast on the floor (spec #30, user story 91): each page template (home, each
 * Article Type, Glossary Entry, search results, Product Area, Role Path, Release Note, retired
 * notice, page not found) is usable within 3 seconds on a slow 4G connection, so help is quicker
 * than asking. Each template is timed on its heaviest page (the most bytes to load), from a cold
 * start, at a handheld's width; "usable" is the page's load event: the page, its styles, fonts,
 * script and the pictures it shows first have all arrived.
 *
 * The slow 4G profile is Chrome DevTools' and Lighthouse's: each request answered 562.5 ms after
 * it was sent, 1.44 Mbit/s down and 675 kbit/s up. Over the content under test the app answers
 * in this process, so the profile is simulated on each answer, the server's own time included;
 * at the real address (--speed-at) Chromium throttles the network itself.
 *
 * @phpstan-import-type Timing from PagesRun
 */
final readonly class FastCheck implements Check
{
    /** @var array{name: string, latency_ms: float, download_bytes_per_second: int, upload_bytes_per_second: int} */
    public const PROFILE = [
        'name' => 'slow 4G',
        'latency_ms' => 562.5,
        'download_bytes_per_second' => 188_744, // 1,474.56 kbit/s: 1.6 Mbit/s less 10%
        'upload_bytes_per_second' => 86_400, // 675 kbit/s: 750 kbit/s less 10%
    ];

    /** Usable within 3 seconds (user story 91). */
    public const LIMIT_MS = 3000;

    public function run(CheckContext $context, Findings $findings): void
    {
        if (! $context->checkPages && $context->speedAt === null) {
            $findings->notRun('Fast on the floor (Launch Check 11): it times each page template in headless Chromium (give --pages, and --speed-at=<address> for the real address)');

            return;
        }

        $run = $context->pages();
        if ($run->problem !== null) {
            $findings->fail('fast.pages', CheckContext::displayPath($context->contentPath), null, "the page templates couldn't be timed: {$run->problem}");

            return;
        }

        if ($context->checkPages) {
            foreach ($run->timings as $timing) {
                $this->check($timing, $timing['address'], $run, $findings);
            }
        }

        if ($run->realAddress !== null) {
            $this->atTheRealAddress($run->realAddress, $run, $findings);
        }

        $timed = array_unique(array_map(fn (Page $page): ?string => $page->template, $run->pages));
        $untimed = array_values(array_diff(self::templates(), $timed));
        if ($untimed !== []) {
            $findings->notRun('Fast on the floor: no page uses the '.self::list($untimed).' '.(count($untimed) === 1 ? 'template' : 'templates').' yet, so '.(count($untimed) === 1 ? 'it wasn\'t' : 'they weren\'t').' timed');
        }
    }

    private function atTheRealAddress(string $address, PagesRun $run, Findings $findings): void
    {
        $origin = rtrim($address, '/');
        if ($run->realProblem !== null) {
            $findings->fail('fast.real-address', $origin, null, "the page templates couldn't be timed at the real address: {$run->realProblem}");

            return;
        }

        foreach ($run->realTimings as $timing) {
            $answeredBy = parse_url($timing['url'], PHP_URL_HOST);
            if ($answeredBy !== parse_url($origin, PHP_URL_HOST)) {
                $findings->fail('fast.real-address', $origin.$timing['address'], null,
                    "{$this->templateOf($timing)} was answered by {$answeredBy}, not the help centre (Cloudflare Access's sign-in?): set CF_ACCESS_CLIENT_ID and CF_ACCESS_CLIENT_SECRET to the Access service token");

                continue;
            }
            $this->check($timing, $origin.$timing['address'], $run, $findings);
        }
    }

    /** @param  Timing  $timing */
    private function check(array $timing, string $where, PagesRun $run, Findings $findings): void
    {
        $expected = $run->page($timing['address'])->status ?? 200;
        if (isset($timing['error'])) {
            $findings->fail('fast.page', $where, null, "{$this->templateOf($timing)} couldn't be opened: {$timing['error']}");

            return;
        }
        if ($timing['status'] !== $expected) {
            $findings->fail('fast.page', $where, null, "{$this->templateOf($timing)} answered {$timing['status']}, where a reader should get {$expected}");

            return;
        }
        if ($timing['ms'] > self::LIMIT_MS) {
            $findings->fail('fast.slow', $where, null, sprintf(
                '%s took %.1f seconds to be usable on a %s connection, over the %d second limit (its heaviest page: %s KB in %d %s)',
                ucfirst($this->templateOf($timing)), $timing['ms'] / 1000, self::PROFILE['name'], self::LIMIT_MS / 1000,
                number_format($timing['bytes'] / 1024), $timing['requests'], $timing['requests'] === 1 ? 'request' : 'requests',
            ));
        }
    }

    /** @param  Timing  $timing */
    private function templateOf(array $timing): string
    {
        return "the {$timing['template']} template";
    }

    /** @return list<string> Every template the spec names. */
    private static function templates(): array
    {
        return [
            Page::HOME,
            ...array_map(fn (ArticleType $type): string => "{$type->value} Article", ArticleType::cases()),
            Page::GLOSSARY_ENTRY,
            Page::SEARCH_RESULTS,
            Page::PRODUCT_AREA,
            Page::ROLE_PATH,
            Page::RELEASE_NOTE,
            Page::RETIRED_NOTICE,
            Page::PAGE_NOT_FOUND,
        ];
    }

    /** @param  list<string>  $items */
    private static function list(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? $last : implode(', ', $items).' and '.$last;
    }
}
