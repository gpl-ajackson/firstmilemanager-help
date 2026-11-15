<?php

use App\Pictures\BrokenRecipe;
use App\Pictures\TakenPicture;
use Tests\Support\FakeStockist;

/*
 * The one thing a Picture outlines (spec #30 "Pictures", ADR-0005). On a Picture that keeps the
 * screen, the camera first scrolls to the outlined thing when it is below the fold, as a person
 * would before pressing it: in the page, or inside a panel that scrolls on its own, such as the
 * Rapid Scanner's slide-over on a handheld.
 */

/** Pure blue, which nothing else on the fake's pages uses: any pixel of it is the button. */
const BUTTON_BLUE = [0, 0, 255];

function belowTheFold(string $around): string
{
    $button = '<button type="button" style="background: rgb(0, 0, 255); color: white; padding: 12px">Relocate</button>';

    return str_replace('{button}', $button, $around);
}

/** Whether the PNG at $path shows the button. */
function showsTheButton(string $path): bool
{
    $image = imagecreatefrompng($path);
    expect($image)->not->toBeFalse();
    [$red, $green, $blue] = BUTTON_BLUE;
    for ($y = 0; $y < imagesy($image); $y += 2) {
        for ($x = 0; $x < imagesx($image); $x += 2) {
            $colour = imagecolorat($image, $x, $y);
            if ((($colour >> 16) & 0xFF) === $red && (($colour >> 8) & 0xFF) === $green && ($colour & 0xFF) === $blue) {
                return true;
            }
        }
    }

    return false;
}

function scannedRecipe(array $fields = []): array
{
    return [
        'signs_in_as' => 'Dan Price',
        'starts_on' => 'Dashboard',
        'device' => 'handheld',
        'keep' => 'screen',
        'outline' => 'Relocate',
        'alt' => 'The Rapid Scanner showing a pallet, with Relocate among its tasks.',
        'articles' => ['relocate-a-stock-container'],
        ...$fields,
    ];
}

function whyNot(TakenPicture|BrokenRecipe $result): string
{
    return $result instanceof BrokenRecipe ? (string) $result : '';
}

it('scrolls the page to an outlined button below the fold of a kept screen', function () {
    $stockist = FakeStockist::start()->page('Dashboard', belowTheFold('<h1>Scanner</h1><div style="height: 1500px"></div>{button}'));

    $picture = $stockist->take(recipes()->recipe('scanner-in-page', scannedRecipe()), 'scanner-in-page');

    expect($picture)->toBeInstanceOf(TakenPicture::class, whyNot($picture))
        ->and([$picture->width, $picture->height])->toBe([720, 1280])
        ->and(showsTheButton($picture->path))->toBeTrue();
});

it('scrolls a panel that scrolls on its own to an outlined button below its fold', function () {
    $stockist = FakeStockist::start()->page('Dashboard', belowTheFold(<<<'HTML'
        <button type="button" onclick="document.getElementById('scanner').hidden = false">Open the rapid scanner</button>
        <div id="scanner" hidden role="dialog" aria-label="Rapid Scanner" style="position: fixed; inset: 0; overflow-y: auto; background: white">
            <h2>Rapid Scanner</h2>
            <div style="height: 1500px"></div>
            {button}
        </div>
        HTML));

    $recipe = scannedRecipe(['steps' => [['select' => 'Open the rapid scanner'], ['wait_for' => 'Rapid Scanner']]]);
    $picture = $stockist->take(recipes()->recipe('scanner-in-panel', $recipe), 'scanner-in-panel');

    expect($picture)->toBeInstanceOf(TakenPicture::class, whyNot($picture))
        ->and([$picture->width, $picture->height])->toBe([720, 1280])
        ->and(showsTheButton($picture->path))->toBeTrue();
});

it('still reports an outline outside the parts a Recipe keeps by their words', function () {
    $stockist = FakeStockist::start()->page('Dashboard', belowTheFold(<<<'HTML'
        <section><h2>Stock Container</h2><p>PAL-000002</p></section>
        <section><h2>Tasks</h2>{button}</section>
        HTML));

    $broken = $stockist->take(recipes()->recipe('scanner-kept-part', scannedRecipe(['keep' => 'Stock Container'])), 'scanner-kept-part');

    expect($broken)->toBeInstanceOf(BrokenRecipe::class)
        ->and((string) $broken)->toContain('outline (outline: Relocate): "Relocate" is outside what is kept');
});
