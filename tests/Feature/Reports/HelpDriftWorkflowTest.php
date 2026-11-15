<?php

use Symfony\Component\Yaml\Yaml;

/*
 * The Help Drift Report's workflow (spec #30, Further Notes 3): it runs on a schedule on the
 * first working day of each month, and can be run by hand, raising the report with every
 * Picture retaken from the main project's main.
 */

/** @return array<mixed> */
function driftWorkflow(): array
{
    return Yaml::parseFile(base_path('.github/workflows/help-drift-report.yml'));
}

it('runs on the 1st to the 7th of each month and by hand, asking first whether today is the first working day', function () {
    $workflow = driftWorkflow();
    $on = $workflow['on'] ?? $workflow[true];

    expect($on['schedule'])->toBe([['cron' => '0 6 1-7 * *']])
        ->and($on)->toHaveKey('workflow_dispatch')
        ->and($workflow['jobs']['report-day']['if'])->toBe("github.event_name == 'schedule'")
        ->and(collect($workflow['jobs']['report-day']['steps'])->pluck('run')->implode("\n"))->toContain('php artisan help:report-day')
        ->and($workflow['jobs']['help-drift-report']['needs'])->toBe('report-day')
        ->and($workflow['jobs']['help-drift-report']['if'])->toContain("github.event_name == 'workflow_dispatch' || needs.report-day.outputs.first == 'true'");
});

it('reads the main project\'s main with its whole history and the read-only key, retakes every Picture and raises the issue', function () {
    $job = driftWorkflow()['jobs']['help-drift-report'];
    $steps = collect($job['steps']);
    $mainProject = $steps->firstWhere('name', 'Check out the main project at main')['with'];
    $run = (string) $steps->firstWhere('name', 'Make the report and raise it')['run'];

    expect($mainProject)->toMatchArray(['repository' => 'gpl-ajackson/firstmilemanager', 'ref' => 'main', 'token' => '${{ secrets.FMM_READ_TOKEN }}', 'fetch-depth' => 0])
        ->and($job['permissions'])->toBe(['contents' => 'read', 'issues' => 'write', 'pull-requests' => 'read'])
        ->and($job['services'])->toHaveKey('postgres')
        ->and($run)->toContain('php artisan help:drift-report')
        ->and($run)->toContain('--take-pictures')
        ->and($run)->toContain('--post-to="$REPOSITORY"');
});
