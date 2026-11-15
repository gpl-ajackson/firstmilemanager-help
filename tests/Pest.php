<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DriftReportRun;
use Tests\Support\FixtureContent;
use Tests\Support\FixtureMainProject;
use Tests\Support\FixtureMarketingSite;
use Tests\Support\FixtureRecipes;
use Tests\Support\FixtureTemplates;
use Tests\Support\LaunchCheckRun;
use Tests\Support\ReaderPage;
use Tests\Support\ReleaseCheckRun;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests go through seam 1 of spec #30: publish a fixture content folder through the
| same rebuild a deploy runs (help:rebuild), then request pages as a reader. They assert on what
| a reader sees and what is recorded, never on the database copy or the renderer's output.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// The picture tool's own tests take real Pictures from Stockist, so they need no help-centre
// database and run apart from the rest: php artisan test --testsuite=Pictures.
pest()->extend(TestCase::class)->in('Pictures');

/*
|--------------------------------------------------------------------------
| Constants
|--------------------------------------------------------------------------
*/

/**
 * A reader's browser, for tests about what is counted: the test client's own user agent
 * ("Symfony") is neither a browser nor an obvious bot. withHeader('User-Agent', A_READER).
 */
const A_READER = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * A new, empty fixture content folder. Add Articles to it, then publish() it.
 */
function content(): FixtureContent
{
    return FixtureContent::make();
}

/**
 * A new folder shaped like a checkout of the main project, with today's menus and Team Roles.
 */
function mainProject(): FixtureMainProject
{
    return FixtureMainProject::make();
}

/**
 * A new folder shaped like a checkout of the marketing site at main: its src/data/site.ts, with
 * the facts every fixture content folder starts with.
 */
function marketingSite(): FixtureMarketingSite
{
    return FixtureMarketingSite::make();
}

/**
 * A new, empty docs/writing/templates folder. Add Article Templates to it.
 */
function templates(): FixtureTemplates
{
    return FixtureTemplates::make();
}

/**
 * The Launch Checks (seam 2) over a fixture content folder; run() runs them.
 */
function launchChecks(FixtureContent $content): LaunchCheckRun
{
    return new LaunchCheckRun($content);
}

/**
 * The Help Drift Report over a fixture content folder, from fixture repositories; run() runs it.
 */
function driftReport(FixtureContent $content): DriftReportRun
{
    return new DriftReportRun($content);
}

/**
 * A new, empty fixture pictures folder. Add Picture Recipes to it, then take() them.
 */
function recipes(): FixtureRecipes
{
    return FixtureRecipes::make();
}

/**
 * Each result's title and where its link takes the reader: result links go through the search
 * service's "opened" address (#44), which redirects to the Article; the browser keeps the
 * link's #fragment, so the Article opens at its Section.
 *
 * $links selects the links: Article results by default, '.glossary-answer .answer-term a' for
 * Glossary answers.
 *
 * @return list<array{0: string, 1: string}>
 */
function whereResultsOpen(TestCase $test, ReaderPage $page, string $links = '.results .result-title a'): array
{
    return array_map(function (array $link) use ($test): array {
        $location = (string) $test->get(strtok($link[1], '#'))->assertRedirect()->headers->get('Location');
        $fragment = parse_url($link[1], PHP_URL_FRAGMENT);

        return [$link[0], parse_url($location, PHP_URL_PATH).($fragment !== null ? '#'.$fragment : '')];
    }, $page->links($links));
}

/**
 * The headers a reader's browser sends with a post from one of the help centre's own pages
 * (SameOrigin lets only those through). withHeaders(postedFromAHelpPage()).
 *
 * @return array<string, string>
 */
function postedFromAHelpPage(): array
{
    return ['User-Agent' => A_READER, 'Origin' => rtrim(url('/'), '/'), 'Sec-Fetch-Site' => 'same-origin'];
}

/**
 * The Release Check (#68) over a fixture content folder, from fixture repositories; run() runs it.
 */
function releaseCheck(FixtureContent $content): ReleaseCheckRun
{
    return new ReleaseCheckRun($content);
}
