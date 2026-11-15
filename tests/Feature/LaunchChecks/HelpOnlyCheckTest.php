<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/*
 * The Help only check: a support-site pull request links its main-project pull request, or says
 * "Help only: <reason>" (plan S7, ADR-0003).
 */

it('fails a description with neither a main-project pull request nor "Help only: <reason>"', function (string $description) {
    $run = launchChecks(content())->description($description)->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe([
            'pull request description [help-only.description] links no main-project pull request (https://github.com/gpl-ajackson/firstmilemanager/pull/<number>) and doesn\'t say "Help only: <reason>"',
        ]);
})->with([
    'nothing' => [''],
    'a summary only' => ["Adds the Inbound Articles.\n"],
    '"Help only:" with no reason' => ["Adds a page.\n\nHelp only:\n"],
    'a link to this repository\'s pull request' => ['Follows https://github.com/gpl-ajackson/firstmilemanager-help/pull/31'],
    'a main-project issue, not a pull request' => ['For https://github.com/gpl-ajackson/firstmilemanager/issues/201'],
    'the rule only inside the template\'s instructions' => ["<!-- Link the main-project pull request, or write Help only: <reason> -->\nAdds a page."],
]);

it('passes a description that links a main-project pull request or says why it has none', function (string $description) {
    $run = launchChecks(content())->description($description)->run();

    expect($run->exitCode)->toBe(Command::SUCCESS)
        ->and($run->failures())->toBe([]);
})->with([
    'a main-project pull request' => ["Help for https://github.com/gpl-ajackson/firstmilemanager/pull/261\n"],
    'Help only with a reason' => ["Fixes a typo.\n\nHelp only: typo in an Article\n"],
    'Help only in a list, any capitals' => ["- help only: SR1 launch content foundation\n"],
]);

it('doesn\'t run the Help only check without a pull request description', function () {
    $run = launchChecks(content())->run();

    expect($run->exitCode)->toBe(Command::SUCCESS)
        ->and($run->output)->toContain('Not run: Help only check: no pull request description was given.');
});

it('tells CI which main-project ref to check out', function (string $description, string $ref) {
    $file = (string) tempnam(sys_get_temp_dir(), 'pr-');
    file_put_contents($file, $description);

    Artisan::call('help:paired-change', ['--pull-request-description' => $file]);

    expect(trim(Artisan::output()))->toBe($ref);
})->with([
    'a paired change' => ['Help for https://github.com/gpl-ajackson/firstmilemanager/pull/261', 'refs/pull/261/head'],
    'help only' => ['Help only: typo', 'main'],
]);

it('refuses to run without a main-project checkout', function () {
    $exitCode = Artisan::call('help:launch-checks', ['--content' => content()->path()]);

    expect($exitCode)->toBe(Command::INVALID)
        ->and(Artisan::output())->toContain('Give --main-project=<path>: a checkout of gpl-ajackson/firstmilemanager at the ref under test.');
});
