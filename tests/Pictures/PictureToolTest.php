<?php

use Tests\Support\FixtureRecipes;

/*
 * The picture tool against a real Stockist (spec #30 seam 3): fixture Recipes are taken through
 * pictures:take from Stockist at PICTURES_STOCKIST_REF, seeded and held at the Picture Date.
 * Slow (a few minutes the first time, while Stockist installs and seeds), so these run on
 * their own: php artisan test --testsuite=Pictures (see pictures/README.md).
 */

/** A desk Recipe: the Locations list searched for the quarantine bays, New Location outlined. */
function locationsRecipe(): array
{
    return [
        'signs_in_as' => 'Sarah Whitfield',
        'starts_on' => 'Warehouses › Locations',
        'device' => 'desk',
        'steps' => [['enter' => ['Search' => 'QA-0']]],
        'keep' => ['Locations', 'Per page'],
        'outline' => 'New Location',
        'alt' => 'The Locations list searched for QA-0, with New Location at the top right.',
        'articles' => ['add-a-location'],
    ];
}

/**
 * A handheld Recipe: a pallet scanned in the Rapid Scanner, Move outlined (the console's first
 * task for a Stock Container since stockist#432 replaced the old scanner and its Relocate).
 * Signed in as Lukasz Nowak, who has no pick list in the demo: the scanner opens on a picker's
 * list, and a pallet scanned there is refused ("Scan the Location first").
 */
function scannerRecipe(): array
{
    return [
        'signs_in_as' => 'Lukasz Nowak',
        'starts_on' => 'Dashboard',
        'device' => 'handheld',
        'steps' => [
            ['select' => 'Open the Rapid Scanner'],
            ['scan' => 'PAL-000002'],
            ['wait_for' => 'Stock Container'],
        ],
        'keep' => 'screen',
        'outline' => 'Move',
        'alt' => 'The Rapid Scanner showing pallet PAL-000002, with Move first among its tasks.',
        'articles' => ['move-a-container'],
    ];
}

/** A desk Recipe that keeps the whole screen and outlines nothing. */
function clientsRecipe(): array
{
    return [
        'signs_in_as' => 'Sarah Whitfield',
        'starts_on' => 'Clients',
        'device' => 'desk',
        'keep' => 'screen',
        'outline' => null,
        'alt' => 'The Clients list, one row for each client Trentside stores stock for.',
        'articles' => ['find-a-client'],
    ];
}

/** @return array{width: int, height: int} */
function pictureSize(string $path): array
{
    $size = getimagesize($path);
    expect($size)->not->toBeFalse("{$path} is not an image");

    return ['width' => $size[0], 'height' => $size[1]];
}

it('takes desk and handheld Pictures at double density, the same image every time', function () {
    $recipes = recipes()
        ->recipe('warehouses-locations-search', locationsRecipe())
        ->recipe('rapid-scanner-stock-container', scannerRecipe())
        ->recipe('clients-list', clientsRecipe());

    $first = FixtureRecipes::folder();
    $run = $recipes->take(['--into' => $first]);

    expect($run['exitCode'])->toBe(0, $run['output'])
        ->and($run['output'])->toContain('TAKEN warehouses-locations-search desk 2560x')
        ->and($run['output'])->toContain('TAKEN rapid-scanner-stock-container handheld 720x')
        ->and($run['output'])->toContain('3 Pictures taken, 0 Recipes broken')
        ->and(pictureSize("{$first}/warehouses-locations-search.png")['width'])->toBe(2560)
        ->and(pictureSize("{$first}/rapid-scanner-stock-container.png"))->toBe(['width' => 720, 'height' => 1280])
        ->and(pictureSize("{$first}/clients-list.png"))->toBe(['width' => 2560, 'height' => 1600]);

    // Cropped to what is kept: the Locations heading down to the foot of the list, not the screen.
    expect(pictureSize("{$first}/warehouses-locations-search.png")['height'])->toBeLessThan(2 * 1400)->toBeGreaterThan(2 * 600);

    $second = FixtureRecipes::folder();
    $again = $recipes->take(['--into' => $second]);

    expect($again['exitCode'])->toBe(0, $again['output']);
    foreach (['warehouses-locations-search', 'rapid-scanner-stock-container', 'clients-list'] as $name) {
        expect(md5_file("{$second}/{$name}.png"))->toBe(md5_file("{$first}/{$name}.png"), "{$name} changed between two takes");
    }
});

it('reports a Recipe whose step names a button that is not there as broken at that step', function () {
    $into = FixtureRecipes::folder();
    $run = recipes()
        ->recipe('warehouses-locations-new', [
            ...locationsRecipe(),
            'steps' => [['enter' => ['Search' => 'QA-0']], ['select' => 'Make a Location']],
        ])
        ->take(['--into' => $into]);

    expect($run['exitCode'])->toBe(1)
        ->and($run['output'])->toContain('BROKEN warehouses-locations-new.yaml: step 2 (select: Make a Location): nothing on the screen shows "Make a Location"')
        ->and($run['output'])->toContain('The screen where it broke: ')
        ->and($run['output'])->toContain('0 Pictures taken, 1 Recipe broken')
        ->and("{$into}/warehouses-locations-new.png")->not->toBeFile();
});

it('reports a Recipe signing in as someone Trentside does not have', function () {
    $run = recipes()
        ->recipe('clients-list', [...clientsRecipe(), 'signs_in_as' => 'Sara Whitfield'])
        ->take(['--into' => FixtureRecipes::folder()]);

    expect($run['exitCode'])->toBe(1)
        ->and($run['output'])->toContain('BROKEN clients-list.yaml: signs_in_as (signs_in_as: Sara Whitfield): no one in the Trentside demo is called Sara Whitfield');
});
