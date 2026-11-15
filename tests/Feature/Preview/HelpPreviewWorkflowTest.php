<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/*
 * The Help Preview workflow (#59): each pull request's preview deployed to Cloudflare Pages with
 * its link posted on the pull request, and removed when it closes. GitHub Actions and Cloudflare
 * can't be reached from a test, so the workflow is read as written, and its removal step's script
 * is run against a fake of Cloudflare's deployments API.
 */

/** @return array<mixed> */
function previewWorkflow(): array
{
    return Yaml::parseFile(base_path('.github/workflows/help-preview.yml'));
}

/**
 * One job's step by its name.
 *
 * @return array<string, mixed>
 */
function previewStep(string $job, string $name): array
{
    $step = collect(previewWorkflow()['jobs'][$job]['steps'])->firstWhere('name', $name);
    expect($step)->not->toBeNull("The {$job} job has no step \"{$name}\"");

    return $step;
}

it('runs for each pull request as it opens, gets a push, reopens and closes, one run at a time per pull request', function () {
    $workflow = previewWorkflow();
    $on = $workflow['on'] ?? $workflow[true];

    expect($on)->toBe(['pull_request' => ['types' => ['opened', 'synchronize', 'reopened', 'closed']]])
        ->and($workflow['concurrency']['group'])->toBe('help-preview-${{ github.event.pull_request.number }}')
        // A push cancels the last push's preview; removing a closed one never cancels a deploy.
        ->and($workflow['concurrency']['cancel-in-progress'])->toBe("\${{ github.event.action != 'closed' }}")
        ->and($workflow['jobs']['preview']['if'])->toBe("github.event.action != 'closed'")
        ->and($workflow['jobs']['remove']['if'])->toBe("github.event.action == 'closed'");
});

it('writes the preview and deploys it with the pinned wrangler as the pull request\'s own branch', function () {
    $workflow = previewWorkflow();
    $deploy = previewStep('preview', 'Deploy it to Cloudflare Pages');

    expect(previewStep('preview', 'Write the Help Preview')['run'])
        ->toBe('php artisan help:preview --into="$RUNNER_TEMP/help-preview" --pull-request="$PULL_REQUEST"')
        ->and($workflow['env']['WRANGLER'])->toMatch('/^wrangler@\d+\.\d+\.\d+$/')
        ->and($deploy['run'])->toContain('npx --yes "$WRANGLER" pages deploy "$RUNNER_TEMP/help-preview"')
        ->toContain('--project-name="$HELP_PREVIEW_PAGES_PROJECT"')
        ->toContain('--branch="pr-$PULL_REQUEST"')
        ->and($deploy['env'])->toMatchArray([
            'CLOUDFLARE_API_TOKEN' => '${{ secrets.CLOUDFLARE_API_TOKEN }}',
            'CLOUDFLARE_ACCOUNT_ID' => '${{ secrets.CLOUDFLARE_ACCOUNT_ID }}',
        ])
        // No tests here: ci.yml and the Launch Checks run on every pull request on their own.
        ->and(collect($workflow['jobs']['preview']['steps'])->pluck('run')->implode("\n"))->not->toContain('artisan test');
});

it('posts one comment with the preview\'s link, saying it asks for a Cloudflare Access sign-in', function () {
    $comment = previewStep('preview', 'Post the preview\'s link on the pull request');

    expect($comment['continue-on-error'])->toBeTrue()
        ->and($comment['run'])->toContain('link="https://pr-$PULL_REQUEST.$HELP_PREVIEW_PAGES_PROJECT.pages.dev"')
        ->toContain('Cloudflare Access asks you to sign in')
        ->toContain('startswith("<!-- help-preview -->")')
        ->toContain('--method PATCH');
});

it('passes with a warning, building nothing, when the pull request can\'t read the Cloudflare secrets', function () {
    $check = previewStep('preview', 'Check for the Cloudflare secrets');
    $state = sys_get_temp_dir().'/help-preview-workflow-'.Str::lower(Str::random(12));
    File::ensureDirectoryExists($state);
    try {
        $run = Process::env([
            'GITHUB_OUTPUT' => "{$state}/output", 'GITHUB_STEP_SUMMARY' => "{$state}/summary",
            'CLOUDFLARE_API_TOKEN' => '', 'CLOUDFLARE_ACCOUNT_ID' => 'an-account', 'HELP_PREVIEW_PAGES_PROJECT' => '',
        ])->run(['bash', '-e', '-c', $check['run']]);

        expect($run->exitCode())->toBe(0)
            ->and($run->output())->toContain('::warning title=No Help Preview::')->toContain('CLOUDFLARE_API_TOKEN HELP_PREVIEW_PAGES_PROJECT')
            ->and(File::get("{$state}/output"))->toBe("ready=false\n")
            ->and(File::get("{$state}/summary"))->toContain('No Help Preview for this pull request');
    } finally {
        File::deleteDirectory($state);
    }

    // Every later step of the job waits for the secrets.
    foreach (array_slice(previewWorkflow()['jobs']['preview']['steps'], 1) as $step) {
        expect($step['if'])->toContain("steps.secrets.outputs.ready == 'true'");
    }
});

it('shows which accounts and Pages projects the Cloudflare token can see when the deploy fails, changing nothing', function () {
    $deploy = previewStep('preview', 'Deploy it to Cloudflare Pages');
    $show = previewStep('preview', 'Show what the Cloudflare token can see');

    expect($deploy['id'] ?? null)->toBe('deploy')
        ->and($show['if'])->toBe("failure() && steps.secrets.outputs.ready == 'true' && steps.deploy.outcome == 'failure'")
        ->and($show['continue-on-error'])->toBeTrue()
        ->and($show['env'])->toBe([
            'CLOUDFLARE_API_TOKEN' => '${{ secrets.CLOUDFLARE_API_TOKEN }}',
            'CLOUDFLARE_ACCOUNT_ID' => '${{ secrets.CLOUDFLARE_ACCOUNT_ID }}',
        ])
        ->and($show['run'])->toContain('npx --yes "$WRANGLER" whoami')
        ->toContain('npx --yes "$WRANGLER" pages project list')
        ->not->toContain('deploy')->not->toContain('create')->not->toContain('delete');
});

it('keeps the Cloudflare secrets to the steps that use them', function () {
    $workflow = previewWorkflow();
    expect(json_encode($workflow['env']))->not->toContain('secrets.');
    foreach ($workflow['jobs'] as $job) {
        expect(json_encode($job['env'] ?? []))->not->toContain('secrets.');
        foreach ($job['steps'] as $step) {
            expect((string) ($step['run'] ?? ''))->not->toMatch('/echo[^\n]*\$CLOUDFLARE_API_TOKEN/')
                ->and(json_encode($step['with'] ?? []))->not->toContain('secrets.');
        }
    }
});

it('removes every deployment of the closed pull request\'s branch, and no other', function () {
    $state = sys_get_temp_dir().'/help-fake-cloudflare-'.Str::lower(Str::random(12));
    File::ensureDirectoryExists($state);
    $deployments = [];
    foreach (range(1, 30) as $n) {
        // Pull request #7's previews among others', over two pages of the API's answer.
        $deployments[] = ['id' => "deployment-{$n}", 'deployment_trigger' => ['type' => 'ad_hoc', 'metadata' => ['branch' => $n % 3 === 0 ? 'pr-7' : 'pr-70']]];
    }
    File::put("{$state}/deployments.json", (string) json_encode($deployments));
    File::put("{$state}/token.txt", 'a-pages-token');

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    $server = Process::env(['FAKE_CLOUDFLARE_STATE' => $state])->forever()
        ->start([PHP_BINARY, '-S', $address, base_path('tests/Support/fake-cloudflare/router.php')]);
    try {
        foreach (range(1, 50) as $try) {
            if (@fsockopen('127.0.0.1', (int) Str::afterLast($address, ':')) !== false) {
                break;
            }
            usleep(100_000);
        }

        $script = str_replace('https://api.cloudflare.com', "http://{$address}", (string) previewStep('remove', 'Delete the pull request\'s deployments from Cloudflare Pages')['run']);
        $run = Process::env([
            'RUNNER_TEMP' => $state, 'GITHUB_OUTPUT' => "{$state}/output", 'GITHUB_STEP_SUMMARY' => "{$state}/summary",
            'CLOUDFLARE_API_TOKEN' => 'a-pages-token', 'CLOUDFLARE_ACCOUNT_ID' => 'an-account',
            'HELP_PREVIEW_PAGES_PROJECT' => 'fmm-help-previews', 'PULL_REQUEST' => '7',
        ])->run(['bash', '-e', '-c', $script]);

        expect($run->exitCode())->toBe(0, $run->errorOutput())
            ->and($run->output())->toContain('Deleted 10 deployment(s) of the branch pr-7.')
            ->and($run->output().$run->errorOutput())->not->toContain('a-pages-token')
            ->and(File::get("{$state}/output"))->toBe("removed=true\n");

        $left = json_decode(File::get("{$state}/deployments.json"), true);
        expect(collect($left)->pluck('deployment_trigger.metadata.branch')->unique()->values()->all())->toBe(['pr-70'])
            ->and(count($left))->toBe(20);

        $deletes = array_values(array_filter(explode("\n", File::get("{$state}/requests.log")), fn (string $line): bool => str_starts_with($line, 'DELETE')));
        expect($deletes)->toHaveCount(10)
            ->and($deletes[0])->toBe('DELETE /client/v4/accounts/an-account/pages/projects/fmm-help-previews/deployments/deployment-3?force=true');
    } finally {
        $server->signal(SIGTERM);
        $server->wait();
        File::deleteDirectory($state);
    }

    $comment = previewStep('remove', 'Say on the pull request that the preview was removed');
    expect($comment['if'])->toBe("steps.remove.outputs.removed == 'true'")
        ->and($comment['run'])->toContain('Removed: this pull request was closed');
});

it('leaves the Launch Checks to run on every pull request on their own', function () {
    $launchChecks = Yaml::parseFile(base_path('.github/workflows/launch-checks.yml'));
    $on = $launchChecks['on'] ?? $launchChecks[true];

    expect($on['pull_request']['types'])->toContain('opened', 'synchronize', 'reopened')
        ->and(json_encode($launchChecks))->not->toContain('help-preview');
});
