<?php

namespace App\LaunchChecks\Pages;

/**
 * What one run of the page checks saw, shared by Launch Checks 7, 8 and 11 so the content is
 * loaded and every page visited once: each page in light and dark at each width, what a test
 * reader's Search, vote and "Still stuck?" left in the records, and each template's time on the
 * slow 4G profile (here, and at the real address when asked).
 *
 * @phpstan-type Kept array{cookies: list<string>, local: int, session: int, indexedDb: list<string>}
 * @phpstan-type Violation array{id: string, help: string, count: int, targets: list<string>}
 * @phpstan-type View array{scheme: string, width: int, scrollWidth: int, clientWidth: int, overflowing: list<string>, small: list<array{what: string, width: int, height: int}>, searchBoxes: list<array{what: string, height: int}>, violations: list<Violation>}
 * @phpstan-type Scan array{address: string, status: int, error?: string, url: string, bytes: int, views: list<View>, kept: Kept}
 * @phpstan-type Timing array{template: string, address: string, status: int, error?: string, url: string, ms: int, bytes: int, requests: int}
 * @phpstan-type Privacy array{did: list<string>, kept: Kept, typed: string, searches: list<string>, votes: int, stillStuck: int, expected: list<string>, leaks: list<string>}
 */
final readonly class PagesRun
{
    /**
     * @param  list<Page>  $pages
     * @param  list<Scan>  $scans
     * @param  Privacy|null  $privacy
     * @param  list<Timing>  $timings
     * @param  list<Timing>  $realTimings
     */
    public function __construct(
        /** Why the pages of the content under test couldn't be visited; null when they were (or weren't asked for). */
        public ?string $problem = null,
        public array $pages = [],
        public array $scans = [],
        public ?array $privacy = null,
        public array $timings = [],
        /** Each template timed at the real address (help:launch-checks --speed-at). */
        public ?string $realAddress = null,
        public array $realTimings = [],
        public ?string $realProblem = null,
    ) {}

    public static function couldNot(string $why): self
    {
        return new self(problem: $why);
    }

    public function page(string $address): ?Page
    {
        foreach ($this->pages as $page) {
            if ($page->address === $address) {
                return $page;
            }
        }

        return null;
    }
}
