<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * Forge's deploy script (#58), kept in deploy/forge-deploy.sh and pasted into Forge. Forge swaps its
 * release macros for script text wherever their names appear, comments included, so a macro
 * written out in a comment breaks the script (the first deploy failed that way). Forge can't be
 * reached from a test, so the script is read as written, with the macros swapped as Forge would.
 */

const FORGE_RELEASE_MACROS = ['$CREATE_RELEASE()', '$ACTIVATE_RELEASE()'];

it('names each release macro exactly once, on a line of its own', function (string $macro) {
    $lines = explode("\n", File::get(base_path('deploy/forge-deploy.sh')));

    $mentions = array_values(array_filter($lines, fn (string $line) => str_contains($line, $macro)));

    expect($mentions)->toBe([$macro]);
})->with(FORGE_RELEASE_MACROS);

it('is a valid bash script once Forge has swapped its macros in', function () {
    $script = str_replace(
        FORGE_RELEASE_MACROS,
        ["if true; then\n    echo creating\nfi", "if true; then\n    echo activating\nfi"],
        File::get(base_path('deploy/forge-deploy.sh')),
    );

    $check = Process::input($script)->run(['bash', '-n']);

    expect($check->errorOutput())->toBe('')
        ->and($check->successful())->toBeTrue();
});
