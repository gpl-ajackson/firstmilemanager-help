<?php

use App\Pictures\BrokenRecipe;
use App\Pictures\PictureCheck;
use App\Pictures\PictureRun;
use App\Pictures\Recipe;
use App\Pictures\TakenPicture;
use Illuminate\Support\Facades\File;
use Tests\Support\FixtureRecipes;

/*
 * The Picture Check's comparison (spec #30 "Pictures": a changed Picture is one whose difference
 * a reader could see, font smoothing ignored by a threshold), on fixture images, so no Stockist
 * is needed. Each test lays out a pictures folder (the Picture as committed, beside its Recipe)
 * and a run of the picture tool that "retook" it as another fixture image.
 *
 * The fixtures in tests/Fixtures/pictures:
 * - locations.png: a Locations list drawn by headless Chromium at double density.
 * - locations-250-units.png: the same page with QA-01's 240 units changed to 250. The table's
 *   columns move by a fraction of a pixel, so every word in two columns is drawn again at a
 *   slightly different place, as happens between two machines; only the digit really changed.
 * - text-smoothed.png and text-not-smoothed.png: the same words drawn by FreeType (PHP's GD,
 *   DejaVu Sans) with font smoothing on and off, so about 7,000 pixels differ at the letters' edges.
 */
function fixtureImage(string $name): string
{
    return base_path("tests/Fixtures/pictures/{$name}");
}

/**
 * A pictures folder holding each Picture as committed (or none, for null), and a PictureRun
 * in which the tool retook each one as the given fixture image.
 *
 * @param  array<string, array{0: string|null, 1: string}>  $pictures  name => [committed, retaken]
 * @param  array<string, array{0: string, 1: string, 2: string}>  $broken  name => [at, said, reason]
 * @return array{0: string, 1: PictureRun}
 */
function retaken(array $pictures, array $broken = []): array
{
    $recipes = recipes();
    foreach ([...array_keys($pictures), ...array_keys($broken)] as $name) {
        $recipes->recipe($name);
    }
    $retakenInto = FixtureRecipes::folder();

    $taken = [];
    foreach ($pictures as $name => [$committed, $retakenAs]) {
        if ($committed !== null) {
            File::copy(fixtureImage($committed), $recipes->path()."/{$name}.png");
        }
        File::copy(fixtureImage($retakenAs), "{$retakenInto}/{$name}.png");
        [$width, $height] = getimagesize("{$retakenInto}/{$name}.png") ?: [0, 0];
        $taken[] = new TakenPicture(Recipe::read($recipes->path()."/{$name}.yaml"), "{$retakenInto}/{$name}.png", $width, $height);
    }

    $brokenRecipes = [];
    foreach ($broken as $name => [$at, $said, $reason]) {
        $brokenRecipes[] = new BrokenRecipe(Recipe::read($recipes->path()."/{$name}.yaml"), $at, $said, $reason);
    }

    return [$recipes->path(), new PictureRun(str_repeat('a', 40), $taken, $brokenRecipes)];
}

/** A copy of a fixture image with a part painted over, as a hand edit would. */
function handEdited(string $fixture): string
{
    $image = imagecreatefrompng(fixtureImage($fixture));
    // Paints out "12 units" in QA-02's Stock column.
    imagefilledrectangle($image, 905, 335, 1030, 370, imagecolorallocate($image, 255, 255, 255));
    $path = FixtureRecipes::folder().'/hand-edited.png';
    imagepng($image, $path);

    return $path;
}

it('lists a Picture hand-edited after its Recipe ran as changed, with before, after and difference images', function () {
    $edited = handEdited('locations.png');
    [$pictures, $run] = retaken(['warehouses-locations-list' => ['locations.png', 'locations.png']]);
    File::copy($edited, "{$pictures}/warehouses-locations-list.png");
    $artefacts = FixtureRecipes::folder('help-picture-check');

    $check = app(PictureCheck::class)->compare($run, $pictures, $artefacts);

    expect($check->changed)->toHaveCount(1);
    $changed = $check->changed[0];
    expect($changed->recipe->name)->toBe('warehouses-locations-list')
        ->and($changed->before)->toBe("{$artefacts}/warehouses-locations-list.before.png")
        ->and($changed->after)->toBe("{$artefacts}/warehouses-locations-list.after.png")
        ->and($changed->difference)->toBe("{$artefacts}/warehouses-locations-list.difference.png")
        ->and(md5_file($changed->before))->toBe(md5_file($edited))
        ->and(md5_file($changed->after))->toBe(md5_file(fixtureImage('locations.png')))
        ->and(getimagesize($changed->difference))->toMatchArray([0 => 1280, 1 => 520])
        ->and($check->same)->toBe([]);
});

it('does not list Pictures differing only by font smoothing as changed', function () {
    expect(md5_file(fixtureImage('text-smoothed.png')))->not->toBe(md5_file(fixtureImage('text-not-smoothed.png')));
    [$pictures, $run] = retaken([
        'smoothed-then-not' => ['text-smoothed.png', 'text-not-smoothed.png'],
        'not-then-smoothed' => ['text-not-smoothed.png', 'text-smoothed.png'],
    ]);

    $check = app(PictureCheck::class)->compare($run, $pictures, FixtureRecipes::folder());

    expect($check->changed)->toBe([])
        ->and($check->same)->toBe(['smoothed-then-not', 'not-then-smoothed'])
        ->and($check->passed())->toBeTrue();
});

it('lists a changed number on a screen, ignoring the words drawn a fraction of a pixel along', function () {
    [$pictures, $run] = retaken(['warehouses-locations-list' => ['locations.png', 'locations-250-units.png']]);

    $check = app(PictureCheck::class)->compare($run, $pictures, FixtureRecipes::folder());

    // About 7,000 pixels differ, nearly all of them words redrawn as the table's columns moved;
    // only the 4 that became a 5 (about 30 by 30 pixels) is a change a reader could see.
    expect($check->changed)->toHaveCount(1)
        ->and((string) $check->changed[0])->toMatch('/^warehouses-locations-list\.png: \d+ pixels differ, from \(\d+, \d+\) to \(\d+, \d+\)$/');
    preg_match('/(\d+) pixels/', $check->changed[0]->why, $pixels);
    expect((int) $pixels[1])->toBeGreaterThan(50)->toBeLessThan(900);
});

it('lists the same image as unchanged and leaves no images for it', function () {
    [$pictures, $run] = retaken(['warehouses-locations-list' => ['locations.png', 'locations.png']]);
    $artefacts = FixtureRecipes::folder();

    $check = app(PictureCheck::class)->compare($run, $pictures, $artefacts);

    expect($check->changed)->toBe([])
        ->and($check->same)->toBe(['warehouses-locations-list'])
        ->and(File::files($artefacts))->toBe([]);
});

it('does not list a speck too small for a reader to see', function () {
    [$pictures, $run] = retaken(['warehouses-locations-list' => ['locations.png', 'locations.png']]);
    $image = imagecreatefrompng("{$pictures}/warehouses-locations-list.png");
    // Three pixels, in the empty space under the table.
    foreach ([[600, 500], [601, 500], [600, 501]] as [$x, $y]) {
        imagesetpixel($image, $x, $y, imagecolorallocate($image, 0, 0, 0));
    }
    imagepng($image, "{$pictures}/warehouses-locations-list.png");

    $check = app(PictureCheck::class)->compare($run, $pictures, FixtureRecipes::folder());

    expect($check->changed)->toBe([]);
});

it('says where the changed pixels are, so a reader without the images can find them', function () {
    [$pictures, $run] = retaken(['warehouses-locations-list' => ['locations.png', 'locations.png']]);
    $image = imagecreatefrompng("{$pictures}/warehouses-locations-list.png");
    // A 6 by 4 block in the empty space under the table.
    imagefilledrectangle($image, 600, 500, 605, 503, imagecolorallocate($image, 0, 0, 0));
    imagepng($image, "{$pictures}/warehouses-locations-list.png");

    $check = app(PictureCheck::class)->compare($run, $pictures, FixtureRecipes::folder());

    expect($check->changed)->toHaveCount(1)
        ->and($check->changed[0]->why)->toBe('24 pixels differ, from (600, 500) to (605, 503)');
});

it('lists a Picture whose size changed', function () {
    [$pictures, $run] = retaken(['warehouses-locations-list' => ['locations.png', 'locations.png']]);
    $image = imagecreatefrompng("{$pictures}/warehouses-locations-list.png");
    imagepng(imagecrop($image, ['x' => 0, 'y' => 0, 'width' => 1280, 'height' => 480]), "{$pictures}/warehouses-locations-list.png");

    $check = app(PictureCheck::class)->compare($run, $pictures, FixtureRecipes::folder());

    expect($check->changed)->toHaveCount(1)
        ->and($check->changed[0]->why)->toBe('it is 1280×520 now, was 1280×480')
        ->and(getimagesize($check->changed[0]->difference))->toMatchArray([0 => 1280, 1 => 520]);
});

it('lists a Recipe with no Picture yet', function () {
    [$pictures, $run] = retaken(['warehouses-locations-list' => [null, 'locations.png']]);
    $artefacts = FixtureRecipes::folder();

    $check = app(PictureCheck::class)->compare($run, $pictures, $artefacts);

    expect($check->changed)->toHaveCount(1)
        ->and((string) $check->changed[0])->toBe('warehouses-locations-list.png: it has no Picture yet; take it with php artisan pictures:take warehouses-locations-list')
        ->and($check->changed[0]->before)->toBeNull()
        ->and($check->changed[0]->after)->toBe("{$artefacts}/warehouses-locations-list.after.png")
        ->and($check->changed[0]->after)->toBeFile();
});

it('lists a broken Recipe with the step that failed and keeps the screen where it broke', function () {
    [$pictures, $run] = retaken([], ['warehouses-locations-new' => ['step 2', 'select: Make a Location', 'nothing on the screen shows "Make a Location"']]);
    $screen = FixtureRecipes::folder().'/warehouses-locations-new.png';
    File::copy(fixtureImage('locations.png'), $screen);
    $broken = $run->broken[0];
    $run = new PictureRun($run->stockistCommit, [], [new BrokenRecipe($broken->recipe, $broken->at, $broken->said, $broken->reason, $screen)]);
    $artefacts = FixtureRecipes::folder();

    $check = app(PictureCheck::class)->compare($run, $pictures, $artefacts);

    expect($check->passed())->toBeFalse()
        ->and(array_map(strval(...), $check->broken))->toBe(['warehouses-locations-new.yaml: step 2 (select: Make a Location): nothing on the screen shows "Make a Location"'])
        ->and($check->broken[0]->screen)->toBe("{$artefacts}/warehouses-locations-new.broken.png")
        ->and($check->broken[0]->screen)->toBeFile();
});
