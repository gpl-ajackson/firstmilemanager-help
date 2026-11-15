<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\DriftReportRun;
use Tests\Support\FixtureMainProject;
use Tests\Support\FixtureRecipes;
use Tests\Support\LaunchCheckRun;

/*
 * The Picture Check against a real Stockist (spec #30, Launch Check 12): Pictures taken by the
 * picture tool, then a pull request that edits one by hand and breaks another's Recipe, checked
 * as CI checks it. Slow, like the picture tool's own tests (php artisan test --testsuite=Pictures).
 */

/** A shallow checkout of Stockist at the ref the tool takes Pictures from, as CI checks out the main project. */
function stockistCheckout(): string
{
    $path = FixtureRecipes::folder('stockist-checkout');
    $repository = config('help.pictures.stockist_repository');
    $repository = is_dir($repository) ? realpath($repository) : $repository;
    foreach ([['init', '--quiet'], ['fetch', '--quiet', '--depth=1', $repository, config('help.pictures.stockist_ref')], ['checkout', '--quiet', 'FETCH_HEAD']] as $command) {
        Process::path($path)->timeout(300)->run(['git', ...$command])->throw();
    }

    return $path;
}

/** Commits everything in the pictures folder, as the pull request's base; returns the commit. */
function commitPictures(string $folder): string
{
    $git = fn (array $command) => Process::path($folder)
        ->env(['GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.com', 'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.com'])
        ->run(['git', ...$command])->throw();
    $git(['init', '--quiet', '--initial-branch=main']);
    $git(['add', '--all']);
    $git(['commit', '--quiet', '--message', 'Pictures as on main']);

    return trim($git(['rev-parse', 'HEAD'])->output());
}

/** Paints over part of a Picture, as someone touching it up by hand would. */
function paintOver(string $png): void
{
    $image = imagecreatefrompng($png);
    imagefilledrectangle($image, 100, 300, 700, 360, imagecolorallocate($image, 255, 255, 255));
    imagepng($image, $png);
}

const CLIENTS_LIST = [
    'signs_in_as' => 'Sarah Whitfield',
    'starts_on' => 'Clients',
    'device' => 'desk',
    'keep' => 'screen',
    'outline' => null,
    'alt' => 'The Clients list, one row for each client Trentside stores stock for.',
    'articles' => ['find-a-client'],
];

const LOCATIONS_LIST = [
    'signs_in_as' => 'Sarah Whitfield',
    'starts_on' => 'Warehouses › Locations',
    'device' => 'desk',
    'keep' => 'screen',
    'outline' => 'New Location',
    'alt' => 'The Locations list, with New Location at the top right.',
    'articles' => ['add-a-location'],
];

it('fails a hand-edited Picture with before, after and difference images, and a broken Recipe at its step', function () {
    $content = content()
        ->picture('clients-list', CLIENTS_LIST)
        ->picture('warehouses-locations-list', LOCATIONS_LIST)
        ->article('add-a-location', [], "1. Open Warehouses › Locations.\n\n   ![](picture:warehouses-locations-list)\n");
    $taken = Artisan::call('pictures:take', ['--pictures' => $content->picturesPath()]);
    expect($taken)->toBe(0, Artisan::output());
    $base = commitPictures($content->picturesPath());

    // The pull request: one Picture touched up by hand, one Recipe given a step Stockist hasn't.
    paintOver($content->picturesPath().'/clients-list.png');
    $content->pictureFile('warehouses-locations-list.yaml', <<<'YAML'
        signs_in_as: Sarah Whitfield
        starts_on: Warehouses › Locations
        device: desk
        steps:
          - select: Make a Location
        keep: screen
        alt: The Locations list, with New Location at the top right.
        articles: [add-a-location]
        YAML);
    $artefacts = FixtureRecipes::folder('picture-check');

    $run = (new LaunchCheckRun($content))->run([
        '--main-project' => stockistCheckout(),
        '--take-pictures' => 'changed',
        '--pictures-base' => $base,
        '--picture-artefacts' => $artefacts,
    ]);

    $pictures = array_values(array_filter($run->failures(), fn (string $line): bool => str_contains($line, '[pictures.')));
    expect($pictures)->toHaveCount(2, $run->output)
        ->and($pictures[0])->toMatch('/^pictures\/clients-list\.png \[pictures\.retaken\] isn\'t what its Recipe takes from Stockist [0-9a-f]{12} at the Picture Date: [\d,]+ pixels differ, from \(\d+, \d+\) to \(\d+, \d+\)\. Before, after and difference: clients-list\.before\.png, clients-list\.after\.png and clients-list\.difference\.png in /')
        ->and($pictures[1])->toMatch('/^pictures\/warehouses-locations-list\.yaml:5 \[pictures\.broken\] step 1 \(select: Make a Location\) can\'t be followed in Stockist [0-9a-f]{12}: nothing on the screen shows "Make a Location"\. The screen where it broke: warehouses-locations-list\.broken\.png in /')
        ->and(md5_file("{$artefacts}/clients-list.before.png"))->toBe(md5_file($content->picturesPath().'/clients-list.png'))
        ->and("{$artefacts}/clients-list.after.png")->toBeFile()
        ->and("{$artefacts}/clients-list.difference.png")->toBeFile()
        ->and("{$artefacts}/warehouses-locations-list.broken.png")->toBeFile();
});

it('lists the Pictures a Stockist change alters, as a main-project pull request checks them', function () {
    $content = content()->picture('clients-list', CLIENTS_LIST);
    expect(Artisan::call('pictures:take', ['--pictures' => $content->picturesPath()]))->toBe(0, Artisan::output());
    paintOver($content->picturesPath().'/clients-list.png');
    $markdown = FixtureRecipes::folder().'/picture-check.md';

    $exitCode = Artisan::call('pictures:check', ['--pictures' => $content->picturesPath(), '--artefacts' => FixtureRecipes::folder(), '--markdown' => $markdown]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1, $output)
        ->and($output)->toMatch('/CHANGED clients-list\.png: [\d,]+ pixels differ/')
        ->and($output)->toContain('1 Picture changed, 0 the same, 0 Recipes broken')
        ->and(File::get($markdown))->toStartWith("<!-- help-picture-check -->\n## Picture Check")
        ->and(File::get($markdown))->toContain('- `clients-list` (');
});

it('lists a Picture that differs from main in the Help Drift Report, linking the run that holds its images', function () {
    $content = content()->picture('clients-list', CLIENTS_LIST);
    expect(Artisan::call('pictures:take', ['--pictures' => $content->picturesPath()]))->toBe(0, Artisan::output());
    paintOver($content->picturesPath().'/clients-list.png');
    Carbon::setTestNow(CarbonImmutable::parse('2026-12-01 07:00', 'Europe/London'));

    $run = driftReport($content)
        ->against(FixtureMainProject::checkout(stockistCheckout()))
        ->run(['--take-pictures' => true, '--picture-artefacts' => FixtureRecipes::folder('picture-check')]);

    $items = $run->section('Pictures that differ from `main`');
    expect($items)->toHaveCount(1, $run->output)
        ->and($items[0])->toMatch('/^\[`pictures\/clients-list\.png`\]\(https:\/\/github\.com\/gpl-ajackson\/firstmilemanager-help\/blob\/main\/pictures\/clients-list\.png\) \*\*pictures\.retaken\*\*: isn\'t what its Recipe takes from Stockist [0-9a-f]{12} at the Picture Date: [\d,]+ pixels differ, from \(\d+, \d+\) to \(\d+, \d+\)\. /')
        ->and($items[0])->toEndWith(' ([the images are in this run\'s picture-check artefact]('.DriftReportRun::RUN_URL.'))');
});
