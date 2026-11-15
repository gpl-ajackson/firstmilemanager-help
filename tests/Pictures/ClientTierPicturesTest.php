<?php

use App\Pictures\ClientTier;
use App\Pictures\PictureTool;
use App\Pictures\Recipes;
use Tests\Support\FixtureRecipes;

/*
 * Client-tier Pictures from the real Stockist (spec #30 "Pictures", rule 14): Martha Shaw,
 * Hebden Botanicals' Client Account Administrator, with the team renamed "Your 3PL" by its owner
 * in Team Settings and the top bar left out. The rules themselves are covered quickly against a
 * fake Stockist in tests/Feature/Pictures/ClientTierPicturesTest.php.
 */

it('takes a client- Picture showing "Your 3PL" with no top bar, and fails one whose screen shows Trentside', function () {
    $recipes = recipes()
        // On a handheld the menu shows the team's name just below the top bar.
        ->recipe('client-menu', [
            'signs_in_as' => 'Martha Shaw',
            'starts_on' => 'Inbound',
            'device' => 'handheld',
            'steps' => [['select' => 'Expand sidebar']],
            'keep' => 'screen',
            'outline' => 'Your account',
            'alt' => 'The menu, with Your account below your 3PL’s name.',
            'articles' => ['see-your-account'],
        ])
        // Her account page names who last changed it by a Trentside email address.
        ->recipe('client-your-account', [
            'signs_in_as' => 'Martha Shaw',
            'starts_on' => 'Your account',
            'device' => 'desk',
            'keep' => 'screen',
            'outline' => null,
            'alt' => 'Your account page, with its status and who manages it.',
            'articles' => ['see-your-account'],
        ]);

    $run = app(PictureTool::class)->take(Recipes::in($recipes->path()), into: FixtureRecipes::folder());

    expect($run->taken)->toHaveCount(1, implode("\n", array_map(strval(...), $run->broken)));
    $menu = $run->taken[0];
    expect($menu->recipe->name)->toBe('client-menu')
        ->and($menu->shows)->toContain(ClientTier::TEAM_NAME)
        ->and(ClientTier::wordsShown($menu->shows))->toBe([])
        // The handheld's screen less its top bar, so no Stockist logo.
        ->and($menu->width)->toBe(720)
        ->and($menu->height)->toBeLessThan(1280)->toBeGreaterThan(1000);

    expect($run->broken)->toHaveCount(1)
        ->and((string) $run->broken[0])->toStartWith('client-your-account.yaml: text check (a client- Picture never shows Stockist or Trentside): the Picture shows "Trentside" in "')
        ->and((string) $run->broken[0])->toContain('@trentside.example')
        ->and($run->broken[0]->screen)->toBeFile();
});
