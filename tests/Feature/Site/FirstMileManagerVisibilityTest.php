<?php

use Tests\Support\FixtureContent;
use Tests\Support\ReaderPage;

/*
| First Mile Manager's two visibility rules (ADR-0006, help#1). An Article goes public only once
| its feature is on the demo (content/features.yaml); till then it waits, hidden as a Draft is.
| A Joint-only Article is about Stockist, for readers at a Joint Operator: its address opens it,
| but it is never listed, searched or in the sitemap, and says "noindex", so a reader at an
| FMM-only Operator never meets it.
*/

function withFeatures(FixtureContent $content, bool $onDemo): FixtureContent
{
    return $content->file('features.yaml', "Staff sign-in and roles:\n  spec: fmm#57\n  on_demo: ".($onDemo ? 'true' : 'false')."\n");
}

/** Two Published Articles about one feature, linking each other, and one about no feature. */
function aFeaturesHelpCentre(bool $onDemo): FixtureContent
{
    return withFeatures(content()
        ->productAreas(['People' => 'People'])
        ->article('sign-in-to-first-mile-manager', ['title' => 'Sign in to First Mile Manager', 'product_area' => 'People', 'feature' => 'Staff sign-in and roles', 'related_articles' => ['staff-roles-in-first-mile-manager', 'get-started']])
        ->article('staff-roles-in-first-mile-manager', ['title' => 'Staff roles in First Mile Manager', 'type' => 'Concept', 'product_area' => 'People', 'feature' => 'Staff sign-in and roles', 'related_articles' => ['sign-in-to-first-mile-manager', 'get-started']])
        ->article('get-started', ['title' => 'Get started', 'product_area' => 'People', 'related_articles' => ['example-concept', 'example-reference']]), $onDemo);
}

it('hides an Article whose feature isn\'t on the demo yet, everywhere a reader looks', function () {
    aFeaturesHelpCentre(onDemo: false)->publish();

    $this->get('/articles/sign-in-to-first-mile-manager')->assertNotFound();
    expect(ReaderPage::of($this->get('/areas/people'))->texts('.linklist a'))->not->toContain('Sign in to First Mile Manager')
        ->and(ReaderPage::of($this->get('/search?q=sign+in'))->texts('.results .result-title'))->not->toContain('Sign in to First Mile Manager');
    $this->get('/articles/get-started')->assertOk();
});

it('shows it once the feature is on the demo', function () {
    aFeaturesHelpCentre(onDemo: true)->publish();

    $this->get('/articles/sign-in-to-first-mile-manager')->assertOk()->assertSee('Sign in to First Mile Manager');
    expect(ReaderPage::of($this->get('/search?q=sign+in'))->texts('.results .result-title'))->toContain('Sign in to First Mile Manager');
});

it('says how many Articles wait for the demo when it rebuilds', function () {
    ['output' => $output] = aFeaturesHelpCentre(onDemo: false)->rebuild();

    expect($output)->toContain('1 published, 2 waiting for the demo, ');
});

it('refuses an Article naming a feature the list doesn\'t have', function () {
    ['exitCode' => $exitCode, 'output' => $output] = withFeatures(content()->article('plan-a-round', ['feature' => 'Round planning']), true)->rebuild();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('articles/plan-a-round.md: feature: "Round planning" is not a feature in features.yaml');
});

it('refuses a features list whose on_demo isn\'t true or false', function () {
    ['exitCode' => $exitCode, 'output' => $output] = content()->article('plan-a-round')
        ->file('features.yaml', "Round planning:\n  spec: fmm#58\n  on_demo: soon\n")->rebuild();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('features.yaml: on_demo: "Round planning": must be true or false');
});

/** A Joint-only Article about Stockist, with two ordinary Articles. */
function aJointHelpCentre(): FixtureContent
{
    return content()
        ->productAreas(['Settings' => 'Settings'])
        ->article('how-first-mile-manager-connects-to-stockist', ['title' => 'How First Mile Manager connects to Stockist', 'type' => 'Concept', 'product_area' => 'Settings', 'joint_only' => true, 'related_articles' => ['set-up-your-business', 'example-concept']],
            "Stockist keeps your warehouse.\n")
        ->article('set-up-your-business', ['title' => 'Set up your business', 'product_area' => 'Settings', 'related_articles' => ['example-concept', 'example-reference']]);
}

it('opens a Joint-only Article by its address, saying noindex', function () {
    config(['help.going_public' => true]);
    aJointHelpCentre()->publish();

    $page = ReaderPage::of($this->get('/articles/how-first-mile-manager-connects-to-stockist')->assertOk());

    expect($page->text('h1'))->toBe('How First Mile Manager connects to Stockist')
        ->and($page->attributes('meta[name=robots]')[0]['content'] ?? null)->toBe('noindex');
});

it('never lists, searches or maps a Joint-only Article', function () {
    config(['help.going_public' => true]);
    aJointHelpCentre()->publish();

    $listed = implode(' ', ReaderPage::of($this->get('/areas/settings'))->texts('.linklist a'));
    expect($listed)->toContain('Set up your business')->not->toContain('connects to Stockist')
        ->and(ReaderPage::of($this->get('/search?q=connects'))->texts('.results .result-title'))->toBe([])
        ->and((string) $this->get('/sitemap.xml')->getContent())->toContain('/articles/set-up-your-business')->not->toContain('how-first-mile-manager-connects-to-stockist');
    $this->get('/llms.txt')->assertDontSee('how-first-mile-manager-connects-to-stockist');
});

it('lets only a Joint-only Article name Stockist in its Article Name', function () {
    ['exitCode' => $exitCode, 'output' => $output] = content()->article('stockist-basics')->rebuild();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('articles/stockist-basics.md: Article Name: "stockist-basics" must not contain "stockist": only a Joint-only Article (joint_only: true) names Stockist');
});

it('refuses a Joint-only Client-tier Article', function () {
    ['exitCode' => $exitCode, 'output' => $output] = content()->article('your-stockist', ['audience_tier' => 'Client', 'joint_only' => true])->rebuild();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('joint_only: can\'t be on a Client-tier Article');
});

it('lets a Change Summary waiting for the next FMM Release link an Article waiting for the demo', function () {
    $content = aFeaturesHelpCentre(onDemo: false)->file('next-release/fmm-57-sign-in.md', "---\nproduct_area: People\nteam_roles: [Admin]\naffects_clients: false\narticles: [sign-in-to-first-mile-manager]\n---\n\nYou can now sign in to First Mile Manager.\n");

    ['exitCode' => $exitCode, 'output' => $output] = $content->rebuild();

    expect($exitCode)->toBe(0)
        ->and($output)->not->toContain('next-release/');
});
