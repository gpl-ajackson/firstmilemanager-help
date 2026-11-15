<?php

use App\Pictures\BrokenRecipe;
use App\Pictures\TakenPicture;
use Tests\Support\FakeStockist;

/*
 * Client-tier Pictures (spec #30 "Pictures", rule 14, ADR-0005): a Recipe whose Picture Name
 * starts `client-` is taken with the team renamed "Your 3PL" through Team Settings, without the
 * top bar, and fails when what it shows says "Stockist" or "Trentside". The camera follows the
 * Recipes through a fake Stockist here (Tests\Support\FakeStockist), so these run in seconds;
 * tests/Pictures takes them from the real one.
 */

/** Martha Shaw's account page in the fake, showing the team's name in its own words. */
function accountPage(string $more = ''): string
{
    return <<<HTML
        <h1>Hebden Botanicals Ltd</h1>
        <section>
            <h2>Account Management</h2>
            <p>Your stock is kept by {team}.</p>
            <span hidden>Trentside</span>
            <span class="sr-only">Stockist</span>
            {$more}
        </section>
        HTML;
}

function clientRecipe(array $fields = []): array
{
    return [
        'signs_in_as' => 'Martha Shaw',
        'starts_on' => 'Your account',
        'keep' => 'screen',
        'outline' => null,
        'alt' => 'Your account page, with the 3PL that keeps your stock under Account Management.',
        'articles' => ['see-your-account'],
        ...$fields,
    ];
}

/** What went wrong, when a Picture wasn't taken. */
function why(TakenPicture|BrokenRecipe $result): string
{
    return $result instanceof BrokenRecipe ? (string) $result : '';
}

/** How many pixels of the PNG at $path are the fake top bar's red. */
function topBarPixels(string $path): int
{
    $image = imagecreatefrompng($path);
    expect($image)->not->toBeFalse();
    [$red, $green, $blue] = FakeStockist::TOP_BAR_RED;
    $found = 0;
    for ($y = 0; $y < imagesy($image); $y += 4) {
        for ($x = 0; $x < imagesx($image); $x += 4) {
            $colour = imagecolorat($image, $x, $y);
            $found += (int) ((($colour >> 16) & 0xFF) === $red && (($colour >> 8) & 0xFF) === $green && ($colour & 0xFF) === $blue);
        }
    }

    return $found;
}

it('takes a client- Picture with the team renamed "Your 3PL" by its owner in Team Settings, and no top bar', function () {
    $stockist = FakeStockist::start()->page('Your account', accountPage());
    $recipes = recipes()->recipe('client-your-account', clientRecipe());

    $picture = $stockist->take($recipes, 'client-your-account');

    expect($picture)->toBeInstanceOf(TakenPicture::class, why($picture))
        ->and($stockist->renames())->toBe([['by' => FakeStockist::OWNER_EMAIL, 'name' => 'Your 3PL']])
        ->and($picture->shows)->toContain('Your stock is kept by Your 3PL.')
        // The whole screen, less the top bar, at double density.
        ->and([$picture->width, $picture->height])->toBe([2560, 2 * (800 - FakeStockist::TOP_BAR_HEIGHT)])
        ->and(getimagesize($picture->path))->toMatchArray([0 => 2560, 1 => 2 * (800 - FakeStockist::TOP_BAR_HEIGHT)])
        ->and(topBarPixels($picture->path))->toBe(0);
});

it('crops the top bar from a client- Picture that keeps part of the screen', function () {
    $stockist = FakeStockist::start()->page('Your account', accountPage());
    $recipes = recipes()->recipe('client-account-management', clientRecipe(['keep' => ['Hebden Botanicals Ltd', 'Account Management']]));

    $picture = $stockist->take($recipes, 'client-account-management');

    expect($picture)->toBeInstanceOf(TakenPicture::class, why($picture))
        ->and(topBarPixels($picture->path))->toBe(0)
        ->and($picture->shows)->toContain('Hebden Botanicals Ltd')
        ->and($picture->shows)->toContain('Your stock is kept by Your 3PL.');
});

it('fails a client- Recipe whose Picture shows Stockist or Trentside, naming the word', function (string $shown, string $word, string $where) {
    $stockist = FakeStockist::start()->page('Your account', accountPage($shown));
    $recipes = recipes()->recipe('client-your-account', clientRecipe());

    $broken = $stockist->take($recipes, 'client-your-account');

    expect($broken)->toBeInstanceOf(BrokenRecipe::class)
        ->and((string) $broken)->toBe("client-your-account.yaml: text check (a client- Picture never shows Stockist or Trentside): the Picture shows \"{$word}\" in \"{$where}\"")
        ->and($broken->screen)->toBeFile();
})->with([
    'the product in a sentence' => ['<p>Powered by Stockist WMS.</p>', 'Stockist', 'Powered by Stockist WMS.'],
    'the demo 3PL in an email address, any capitals' => ['<p>Created by priya.desai@trentside.example</p>', 'Trentside', 'Created by priya.desai@trentside.example'],
    'typed into a field' => ['<label for="q">Search</label> <input id="q" value="STOCKIST">', 'Stockist', 'STOCKIST'],
    'a logo in the page' => ['<img alt="Stockist logo" width="96" height="24" src="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'96\' height=\'24\'/%3E">', 'Stockist', 'Stockist logo'],
]);

it('names both words when a client- Picture shows both', function () {
    $stockist = FakeStockist::start()->page('Your account', accountPage('<p>Stockist at Trentside.</p>'));
    $recipes = recipes()->recipe('client-your-account', clientRecipe());

    $broken = $stockist->take($recipes, 'client-your-account');

    expect((string) $broken)->toEndWith('the Picture shows "Stockist" in "Stockist at Trentside.", and "Trentside" in "Stockist at Trentside."');
});

it('reports a client- Recipe as broken when the team cannot be renamed in Team Settings', function () {
    $stockist = FakeStockist::start()->withoutTeamSettings()->page('Your account', accountPage());
    $recipes = recipes()->recipe('client-your-account', clientRecipe());

    $broken = $stockist->take($recipes, 'client-your-account');

    expect((string) $broken)->toBe('client-your-account.yaml: team name (Team Settings › Team Name: Your 3PL, as Sarah Whitfield): nothing on the screen shows "Team Settings"')
        ->and($stockist->teamName())->toBe(FakeStockist::TEAM);
});

it('takes staff Pictures as before: the team keeps its name, the top bar stays and no text is checked', function () {
    $stockist = FakeStockist::start()->page('Your account', accountPage('<p>Powered by Stockist WMS.</p>'));
    $recipes = recipes()->recipe('your-account', clientRecipe(['signs_in_as' => 'Sarah Whitfield']));

    $picture = $stockist->take($recipes, 'your-account');

    expect($picture)->toBeInstanceOf(TakenPicture::class, why($picture))
        ->and($stockist->renames())->toBe([])
        ->and($stockist->teamName())->toBe(FakeStockist::TEAM)
        ->and([$picture->width, $picture->height])->toBe([2560, 1600])
        ->and(topBarPixels($picture->path))->toBeGreaterThan(0)
        ->and($picture->shows)->toBe([]);
});
