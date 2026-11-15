<?php

namespace App\LaunchChecks\Accessible;

use App\LaunchChecks\Check;
use App\LaunchChecks\CheckContext;
use App\LaunchChecks\Findings;
use App\LaunchChecks\Pages\PagesRun;

/**
 * Launch Check 7, Accessible and fits (spec #30, user stories 89, 90, 92 and 123): every
 * published page, not a sample, passes an axe WCAG 2.2 AA scan and has no sideways scrolling at
 * 320px, 390px and 1280px, in the light and dark themes (the dark theme scanned for colour, the
 * only thing it changes); every control is at least 44px and the search box at least 52px tall,
 * so a gloved hand can hit them.
 *
 * A link in running text (a sentence, or a list item with other words) is sized by its line, as
 * WCAG 2.5.8's inline exception allows; every other link, button and field must be 44px.
 *
 * @phpstan-import-type Scan from PagesRun
 * @phpstan-import-type View from PagesRun
 */
final readonly class AccessibleCheck implements Check
{
    /** @var list<int> The widths a page is checked at: the smallest handheld, a phone, a desk. */
    public const WIDTHS = [320, 390, 1280];

    /** @var list<string> */
    public const SCHEMES = ['light', 'dark'];

    /** @var list<string> axe's rule tags for WCAG 2.2 level AA (A and AA of 2.0, 2.1 and 2.2). */
    public const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

    /**
     * @var list<string> The WCAG 2.2 AA rules a theme can change (text and link colours): the
     *                   dark theme is scanned for these, the light theme for every rule.
     */
    public const COLOUR_RULES = ['color-contrast', 'link-in-text-block'];

    /** The smallest control a gloved hand hits (user story 90). */
    public const CONTROL_PX = 44;

    /** The search box's smallest height (user story 90). */
    public const SEARCH_BOX_PX = 52;

    public function run(CheckContext $context, Findings $findings): void
    {
        if (! $context->checkPages) {
            $findings->notRun('Accessible and fits (Launch Check 7): it opens every published page in headless Chromium (give --pages)');

            return;
        }

        $run = $context->pages();
        if ($run->problem !== null) {
            $findings->fail('accessible.pages', CheckContext::displayPath($context->contentPath), null, "the pages couldn't be checked: {$run->problem}");

            return;
        }

        foreach ($run->scans as $scan) {
            $this->check($scan, $run, $findings);
        }
    }

    /** @param  Scan  $scan */
    private function check(array $scan, PagesRun $run, Findings $findings): void
    {
        $address = $scan['address'];
        $expected = $run->page($address)->status ?? 200;
        if (isset($scan['error'])) {
            $findings->fail('accessible.page', $address, null, "couldn't be opened: {$scan['error']}");

            return;
        }
        if ($scan['status'] !== $expected) {
            $findings->fail('accessible.page', $address, null, "answered {$scan['status']}, where a reader should get {$expected}");
        }

        /** @var array<string, array{help: string, count: int, targets: list<string>, views: list<View>}> $rules */
        $rules = [];
        /** @var list<View> $sideways */
        $sideways = [];
        /** @var array<string, array{size: string, views: list<View>}> $small */
        $small = [];
        /** @var array<string, array{height: int, views: list<View>}> $short */
        $short = [];
        foreach ($scan['views'] as $view) {
            foreach ($view['violations'] as $violation) {
                $rule = $rules[$violation['id']] ?? ['help' => $violation['help'], 'count' => 0, 'targets' => [], 'views' => []];
                $rule['count'] = max($rule['count'], $violation['count']);
                $rule['targets'] = array_values(array_unique([...$rule['targets'], ...$violation['targets']]));
                $rule['views'][] = $view;
                $rules[$violation['id']] = $rule;
            }
            if ($view['scrollWidth'] > $view['clientWidth']) {
                $sideways[] = $view;
            }
            foreach ($view['small'] as $control) {
                $small[$control['what']] ??= ['size' => "{$control['width']}×{$control['height']}px", 'views' => []];
                $small[$control['what']]['views'][] = $view;
            }
            foreach ($view['searchBoxes'] as $box) {
                $short[$box['what']] ??= ['height' => $box['height'], 'views' => []];
                $short[$box['what']]['views'][] = $view;
            }
        }

        foreach ($rules as $id => $rule) {
            $elements = $rule['count'] === 1 ? '1 element' : "{$rule['count']} elements";
            $findings->fail("accessible.{$id}", $address, null,
                "{$rule['help']} ({$elements}: ".implode(', ', array_slice($rule['targets'], 0, 3)).($rule['count'] > 3 ? ', …' : '').') '
                .self::where($rule['views'], themes: in_array($id, self::COLOUR_RULES, true)));
        }
        if ($sideways !== []) {
            $widest = max(array_map(fn (array $view): int => $view['scrollWidth'] - $view['clientWidth'], $sideways));
            $parts = array_values(array_unique(array_merge(...array_map(fn (array $view): array => $view['overflowing'], $sideways))));
            $findings->fail('accessible.sideways-scrolling', $address, null,
                'scrolls sideways '.self::where($sideways).": the page is {$widest}px wider than the screen".($parts === [] ? '' : ' (too wide: '.implode(', ', array_slice($parts, 0, 3)).')'));
        }
        foreach ($small as $what => $control) {
            $findings->fail('accessible.control-size', $address, null,
                "{$what} is {$control['size']}, smaller than ".self::CONTROL_PX.'px '.self::where($control['views']));
        }
        foreach ($short as $what => $box) {
            $findings->fail('accessible.search-box', $address, null,
                "the search box ({$what}) is {$box['height']}px tall, shorter than ".self::SEARCH_BOX_PX.'px '.self::where($box['views']));
        }
    }

    /**
     * Where a finding was seen: "at every width, light and dark", "at 320px and 390px, light and
     * dark", "at 320px (dark)"; without the themes for what only the light theme was scanned for.
     *
     * @param  list<View>  $views
     */
    private static function where(array $views, bool $themes = true): string
    {
        $schemes = [];
        foreach ($views as $view) {
            $schemes[$view['width']][$view['scheme']] = true;
        }
        ksort($schemes);
        $both = implode(' and ', self::SCHEMES);
        $sameEverywhere = count(array_unique(array_map(fn (array $at): string => implode(' and ', array_keys($at)), $schemes))) === 1;
        if ($sameEverywhere || ! $themes) {
            $at = array_keys(reset($schemes) ?: []);
            sort($at);
            $inThemes = count($at) === count(self::SCHEMES) ? $both : implode(' and ', $at).' only';
            $widths = count($schemes) === count(self::WIDTHS) ? 'every width' : self::list(array_map(fn (int $width): string => "{$width}px", array_keys($schemes)));

            return $themes ? "at {$widths}, {$inThemes}" : "at {$widths}";
        }

        return 'at '.self::list(array_map(
            fn (int $width, array $at): string => "{$width}px (".(count($at) === count(self::SCHEMES) ? $both : implode(' and ', array_keys($at))).')',
            array_keys($schemes), $schemes,
        ));
    }

    /** @param  list<string>  $items */
    private static function list(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items).' and '.$last;
    }
}
