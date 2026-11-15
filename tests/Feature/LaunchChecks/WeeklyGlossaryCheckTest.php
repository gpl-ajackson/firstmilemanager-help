<?php

use Illuminate\Console\Command;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\FixtureContent;
use Tests\Support\FixtureMainProject;

/*
 * The Glossary Check's weekly run (spec #30: "The Glossary Check also runs weekly on a schedule
 * and posts its reports to the Content Owner"): help:glossary-check over the content folder and
 * the main project at main, posting what it found on an issue in this repository assigned to
 * the Content Owner. GitHub is faked at its boundary (seam 4).
 */

const REPOSITORY = 'gpl-ajackson/firstmilemanager-help';

beforeEach(function () {
    putenv('GITHUB_TOKEN=fixture-issues-token');
    $this->travelTo(now()->setDate(2026, 10, 26)->setTime(7, 0));
});

afterEach(function () {
    putenv('GITHUB_TOKEN');
});

function weeklyProject(): FixtureMainProject
{
    return mainProject()->glossary([
        'Location' => 'A place stock is kept.',
        'Dock Override' => 'Letting a delivery in outside its booking.',
    ]);
}

function weeklyContent(): FixtureContent
{
    return content()
        ->termStatus(['Location' => ['status' => 'Published', 'product_area' => 'Warehouses', 'ahead' => true, 'fingerprint' => '6974c41e29f4']])
        ->glossaryEntry('location');
}

/** @param  array<string, mixed>  $options */
function weeklyRun(FixtureContent $content, FixtureMainProject $project, array $options = []): array
{
    $exitCode = Artisan::call('help:glossary-check', [
        '--content' => $content->path(),
        '--main-project' => $project->path(),
        '--main-project-ref' => 'main (4f2a9c1)',
        '--post-to' => REPOSITORY,
        ...$options,
    ]);

    return [$exitCode, Artisan::output()];
}

/** GitHub's issues API for this repository, with the given open issues. */
function fakeIssues(array $openIssues = []): void
{
    Http::fake([
        'api.github.com/repos/'.REPOSITORY.'/issues?*' => Http::response($openIssues),
        'api.github.com/repos/'.REPOSITORY.'/issues' => Http::response(['number' => 91, 'html_url' => 'https://github.com/'.REPOSITORY.'/issues/91'], 201),
        'api.github.com/repos/'.REPOSITORY.'/issues/*/comments' => Http::response(['html_url' => 'https://github.com/'.REPOSITORY.'/issues/57#issuecomment-1'], 201),
    ]);
}

it('opens an issue for the Content Owner with the week\'s reports', function () {
    fakeIssues();

    [$exitCode, $output] = weeklyRun(weeklyContent(), weeklyProject());

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($output)->toContain('Posted the Glossary Check\'s findings: https://github.com/'.REPOSITORY.'/issues/91');
    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'POST' || $request->url() !== 'https://api.github.com/repos/'.REPOSITORY.'/issues') {
            return false;
        }
        $body = (string) $request['body'];

        return $request->hasHeader('Authorization', 'Bearer fixture-issues-token')
            && $request['title'] === 'Glossary Check: what GLOSSARY.md changed'
            && $request['assignees'] === ['gpl-ajackson']
            && str_starts_with($body, '<!-- help-glossary-check -->')
            && str_contains($body, '## Glossary Check, 26 October 2026')
            && str_contains($body, '`term-status.yaml` **glossary.term-status**: "Dock Override" has no Term Status')
            && str_contains($body, '`term-status.yaml:1` **glossary.ahead**: "Location" is marked Ahead')
            && str_contains($body, 'main (4f2a9c1)');
    });
});

it('adds each week\'s reports to the open issue as a comment', function () {
    fakeIssues([
        ['number' => 12, 'body' => 'Something else', 'title' => 'Another issue'],
        ['number' => 40, 'body' => 'A pull request', 'pull_request' => ['url' => 'x']],
        ['number' => 57, 'body' => "<!-- help-glossary-check -->\n## Glossary Check, 19 October 2026", 'title' => 'Glossary Check: what GLOSSARY.md changed'],
    ]);

    [$exitCode, $output] = weeklyRun(weeklyContent(), weeklyProject());

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($output)->toContain('#issuecomment-1');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.github.com/repos/'.REPOSITORY.'/issues/57/comments'
        && str_starts_with((string) $request['body'], '## Glossary Check, 26 October 2026'));
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/'.REPOSITORY.'/issues' && $request->method() === 'POST');
});

it('posts a failing entry too, and exits 1 so the run shows red', function () {
    fakeIssues();

    [$exitCode] = weeklyRun(weeklyContent()->glossaryEntry('dock-bay'), weeklyProject());

    expect($exitCode)->toBe(Command::FAILURE);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains((string) $request['body'], '`glossary/dock-bay.md:2` **glossary.term**: "Dock Bay" is not a term in GLOSSARY.md'));
});

it('posts nothing in a week with nothing to report', function () {
    fakeIssues();
    $content = content()
        ->productAreas(['Warehouses' => 'warehouses.cluster.title'])
        ->termStatus(['Location' => ['status' => 'Published', 'product_area' => 'Warehouses', 'fingerprint' => '6974c41e29f4']])
        ->glossaryEntry('location');

    [$exitCode, $output] = weeklyRun($content, mainProject()->glossary(['Location' => 'A place stock is kept.']));

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($output)->toContain('Nothing to report this week, so nothing was posted.');
    Http::assertNothingSent();
});

it('prints its findings without posting when not asked to post', function () {
    fakeIssues();

    [$exitCode, $output] = weeklyRun(weeklyContent(), weeklyProject(), ['--post-to' => null]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($output)->toContain('REPORT term-status.yaml [glossary.term-status] "Dock Override" has no Term Status');
    Http::assertNothingSent();
});

it('fails, saying why, when GitHub refuses the post', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Resource not accessible by integration'], 403)]);

    [$exitCode, $output] = weeklyRun(weeklyContent(), weeklyProject());

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('The findings couldn\'t be posted to '.REPOSITORY.': GitHub answered 403 (Resource not accessible by integration)');
});

it('needs a GitHub token to post', function () {
    putenv('GITHUB_TOKEN');
    fakeIssues();

    [$exitCode, $output] = weeklyRun(weeklyContent(), weeklyProject());

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('Set GITHUB_TOKEN to a token that can write issues on '.REPOSITORY);
    Http::assertNothingSent();
});
