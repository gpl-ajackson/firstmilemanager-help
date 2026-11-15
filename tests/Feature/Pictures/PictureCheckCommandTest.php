<?php

use Illuminate\Support\Facades\Artisan;
use Tests\Support\FixtureRecipes;

/*
 * php artisan pictures:check, the Picture Check on its own, as a main-project pull request runs
 * it (#65): every Picture retaken from a Stockist and compared. Taking needs a real Stockist
 * (tests/Pictures); here only what is refused before Stockist is reached.
 */

beforeEach(fn () => config(['help.pictures.work_path' => FixtureRecipes::folder('help-pictures-work')]));

it('checks every Recipe first and takes nothing when one is invalid', function () {
    $recipes = recipes()->recipe('clients-list', ['alt' => 'Picture of the Clients list.']);

    $exitCode = Artisan::call('pictures:check', ['--pictures' => $recipes->path()]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('INVALID clients-list.yaml: alt: must not start "Screenshot", "Image" or "Picture"');
});

it('exits 2 and says why when no Stockist can be made', function () {
    $recipes = recipes()->recipe('clients-list');
    $notACheckout = FixtureRecipes::folder('not-stockist');

    $exitCode = Artisan::call('pictures:check', ['--pictures' => $recipes->path(), '--stockist' => $notACheckout, '--ref' => 'HEAD']);

    expect($exitCode)->toBe(2)
        ->and(Artisan::output())->toContain('No Pictures checked: ');
});
