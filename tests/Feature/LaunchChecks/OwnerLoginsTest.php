<?php

use Illuminate\Console\Command;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FixtureContent;

/*
 * With --check-owner-logins (CI), each Article Owner on the list is looked up on GitHub, so a
 * typo or a bot account fails. GitHub is faked here.
 */

beforeEach(fn () => Http::preventStrayRequests());

function ownersContent(): FixtureContent
{
    return content()
        ->productAreas(['Inbound' => 'inbound.cluster.title'])
        ->articleOwners(['gpl-ajackson', 'trentside-writer'])
        ->article('receive-a-delivery');
}

it('passes owners GitHub knows as people', function () {
    Http::fake(['api.github.com/users/*' => Http::response(['type' => 'User'])]);

    $run = launchChecks(ownersContent())->run(['--check-owner-logins' => true]);

    expect($run->exitCode)->toBe(Command::SUCCESS);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/users/trentside-writer');
});

it('fails an owner GitHub doesn\'t know, or knows as a bot or organisation', function () {
    Http::fake([
        'api.github.com/users/gpl-ajackson' => Http::response(['type' => 'Organization']),
        'api.github.com/users/trentside-writer' => Http::response(['message' => 'Not Found'], 404),
    ]);

    $run = launchChecks(ownersContent())->run(['--check-owner-logins' => true]);

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->failures())->toBe([
            'article-owners.yaml:1 [owners.person] "gpl-ajackson" is a GitHub Organization account, not a person\'s',
            'article-owners.yaml:2 [owners.github] "trentside-writer" is not a GitHub account',
        ]);
});

it('reports, without failing, a login GitHub couldn\'t answer for', function () {
    Http::fake(['api.github.com/users/*' => Http::response('', 503)]);

    $run = launchChecks(ownersContent())->run(['--check-owner-logins' => true]);

    expect($run->exitCode)->toBe(Command::SUCCESS)
        ->and($run->reports())->toContain('article-owners.yaml:1 [owners.github] "gpl-ajackson" couldn\'t be looked up on GitHub this time');
});

it('doesn\'t look owners up unless asked', function () {
    $run = launchChecks(ownersContent())->run();

    expect($run->exitCode)->toBe(Command::SUCCESS);
    Http::assertNothingSent();
});
