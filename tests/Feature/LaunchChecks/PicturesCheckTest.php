<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FixtureContent;
use Tests\Support\FixtureRecipes;
use Tests\Support\LaunchCheckRun;

/*
 * Launch Check 12, Pictures (spec #30, seam 2): every Picture has a Recipe whose alt text
 * doesn't start "Screenshot", "Image" or "Picture", every Recipe has its Picture, and, with
 * --take-pictures, every Picture the pull request adds or changes is what its Recipe takes from
 * the main project. Retaking needs a real Stockist, so here only the choice of Pictures and what
 * happens when no Stockist can be made are tested; tests/Pictures takes them for real.
 * Fixture Articles' header data takes lines 1 to 19, so a body starts on line 20.
 */

/** @return list<string> The Picture Check's FAIL lines (and any Picture problem check 1 still lists). */
// The picture tool's work folder, kept apart from a real run's (which holds its lock for minutes).
beforeEach(fn () => config(['help.pictures.work_path' => FixtureRecipes::folder('help-pictures-work')]));

function pictureFailures(LaunchCheckRun $run): array
{
    return array_values(array_filter($run->failures(), fn (string $line): bool => str_contains($line, '[pictures.') || str_contains($line, '[header.picture]')));
}

function picturedContent(): FixtureContent
{
    return content()
        ->picture('warehouses-locations-list', ['articles' => ['add-a-location']])
        ->article('add-a-location', [], "1. Open Warehouses › Locations.\n\n   ![](picture:warehouses-locations-list)\n");
}

/** Makes the pictures folder a git repository with everything in it committed; returns the commit. */
function committedPictures(FixtureContent $content): string
{
    $git = fn (array $command) => Process::path($content->picturesPath())
        ->env(['GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.com', 'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.com'])
        ->run(['git', ...$command])->throw();
    $git(['init', '--quiet', '--initial-branch=main']);
    $git(['add', '--all']);
    $git(['commit', '--quiet', '--message', 'Pictures as on main']);

    return trim($git(['rev-parse', 'HEAD'])->output());
}

it('passes Pictures that each have a valid Recipe, and says the retake was not asked for', function () {
    $run = launchChecks(picturedContent())->run();

    expect(pictureFailures($run))->toBe([])
        ->and($run->output)->toContain('Not run: Pictures compared with what their Recipes take from Stockist (give --take-pictures).');
});

it('runs only the Pictures check with --pictures-only, in a comment of its own', function () {
    $content = picturedContent();
    // An Article with no Owner fails the Article Header check, which a pictures-only run leaves out.
    $content->article('no-owner', ['owner' => null], "1. Open Warehouses.\n");

    $whole = launchChecks($content)->run();
    $pictures = launchChecks($content)->run(['--pictures-only' => true]);

    expect($whole->failures())->not->toBe([])
        ->and($pictures->exitCode)->toBe(Command::SUCCESS)
        ->and($pictures->failures())->toBe([])
        ->and($pictures->output)->toContain('Launch Check 12, Pictures, over')
        ->and($pictures->markdown)->toStartWith("<!-- help-launch-checks-pictures -->\n## Picture Check (Launch Check 12)\n");
});

it('fails a Picture with no Recipe, where it is used and in the pictures folder', function () {
    $content = picturedContent();
    File::delete($content->picturesPath().'/warehouses-locations-list.yaml');

    $run = launchChecks($content)->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and(pictureFailures($run))->toBe([
            'pictures/warehouses-locations-list.png [pictures.recipe] has no Recipe (warehouses-locations-list.yaml), so the picture tool never took it: write its Recipe (pictures/README.md), then php artisan pictures:take warehouses-locations-list',
            'articles/add-a-location.md:22 [pictures.used] "warehouses-locations-list" has no Recipe (warehouses-locations-list.yaml) to give its alt text',
        ]);
});

it('fails a Recipe whose alt text starts "Screenshot", naming its line', function () {
    $content = picturedContent()->picture('warehouses-locations-list', ['alt' => 'Screenshot of the Locations list.']);

    $run = launchChecks($content)->run();

    expect(pictureFailures($run))->toBe([
        'pictures/warehouses-locations-list.yaml:7 [pictures.recipe] alt: must not start "Screenshot", "Image" or "Picture": say what the reader needs from it',
        'articles/add-a-location.md:22 [pictures.used] the Recipe warehouses-locations-list.yaml can\'t be used: alt: must not start "Screenshot", "Image" or "Picture": say what the reader needs from it',
    ]);
});

it('fails a Recipe no Article uses when it is invalid, and a Recipe with no Picture', function () {
    $content = picturedContent()
        ->pictureFile('clients-list.yaml', "signs_in_as: Sarah Whitfield\nstarts_on: Clients\ndevice: desk\nkeep: screen\nalt: Image of the Clients list.\narticles: [find-a-client]\n")
        ->pictureFile('inbound-shipments.yaml', "signs_in_as: Sarah Whitfield\nstarts_on: Inbound › Shipments\ndevice: desk\nkeep: screen\nalt: The Shipments list.\narticles: [find-a-shipment]\n");

    $run = launchChecks($content)->run();

    expect(pictureFailures($run))->toBe([
        'pictures/clients-list.yaml:5 [pictures.recipe] alt: must not start "Screenshot", "Image" or "Picture": say what the reader needs from it',
        'pictures/inbound-shipments.yaml [pictures.taken] has no Picture (inbound-shipments.png): take it with php artisan pictures:take inbound-shipments',
    ]);
});

it('retakes nothing when the pull request changes no Picture', function () {
    $content = picturedContent();
    $base = committedPictures($content);
    $content->article('add-a-location', ['summary' => 'Add a Location to a Warehouse, in one sentence.'], "1. Open Warehouses › Locations.\n\n   ![](picture:warehouses-locations-list)\n");

    $run = launchChecks($content)->run(['--take-pictures' => 'changed', '--pictures-base' => $base]);

    expect(pictureFailures($run))->toBe([])
        ->and($run->output)->not->toContain('Not run: Pictures');
});

it('retakes the Pictures the pull request adds or changes, and fails when no Stockist can be made to take them', function () {
    $content = picturedContent()->picture('clients-list', ['starts_on' => 'Clients']);
    $base = committedPictures($content);
    $content
        ->picture('warehouses-locations-list', ['articles' => ['add-a-location'], 'outline' => 'Per page'])
        ->picture('inbound-shipments', ['starts_on' => 'Inbound › Shipments']);

    // The fixture main project is no git checkout, so no Stockist can be fetched from it.
    $run = launchChecks($content)->run(['--take-pictures' => 'changed', '--pictures-base' => $base]);

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and(pictureFailures($run))->toHaveCount(1)
        ->and(pictureFailures($run)[0])->toStartWith('pictures [pictures.stockist] The Pictures to check (inbound-shipments, warehouses-locations-list) couldn\'t be retaken: ');
});

it('retakes every Picture for the Release Check and the Help Drift Report', function () {
    $run = launchChecks(picturedContent()->picture('clients-list', ['starts_on' => 'Clients']))->run(['--take-pictures' => 'all']);

    expect(pictureFailures($run)[0] ?? '')->toStartWith('pictures [pictures.stockist] The Pictures to check (clients-list, warehouses-locations-list) couldn\'t be retaken: ');
});

it('refuses --take-pictures=changed without the base to compare with', function () {
    $run = launchChecks(picturedContent())->run(['--take-pictures' => 'changed']);

    expect($run->exitCode)->toBe(Command::INVALID)
        ->and($run->output)->toContain('--take-pictures=changed needs --pictures-base=<git ref>');
});
