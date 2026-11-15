<?php

use Illuminate\Console\Command;
use Tests\Support\FixtureContent;

/*
 * Check 1's lengths are posted on the pull request for the reviewer and never fail it: sentences
 * over 20 words, runs of more than ten steps, Articles over their template's guide length.
 */

function lengthyContent(string $body): FixtureContent
{
    return content()
        ->productAreas(['Inbound' => 'inbound.cluster.title'])
        ->articleOwners(['gpl-ajackson'])
        ->article('receive-a-delivery', [], $body);
}

it('reports a sentence over 20 words without failing', function () {
    $body = "## In short\n\nScan it in.\nThen select **Receive** on the Dock Receiving Terminal so that every line of the delivery is counted, checked and put away tidily today.\n";

    $run = launchChecks(lengthyContent($body))->run();

    expect($run->exitCode)->toBe(Command::SUCCESS)
        ->and($run->failures())->toBe([])
        ->and($run->reports())->toContain(
            'articles/receive-a-delivery.md:23 [length.sentence] a sentence of 23 words, over the Style Guide\'s 20: "Then select Receive on the Dock Receiving Terminal…"',
        );
});

it('doesn\'t report a sentence of 20 words, or Stockist\'s words quoted in a message', function () {
    $twenty = 'One two three four five six seven eight nine ten one two three four five six seven eight nine ten.';
    $quoted = '> This location can\'t take this container because it is already full of stock and its limits for weight and size are reached today.';

    $run = launchChecks(lengthyContent("{$twenty}\n\n{$quoted}\n"))->run();

    expect(implode("\n", $run->reports()))->not->toContain('length.sentence');
});

it('reports a run of more than ten steps without failing', function () {
    $steps = implode("\n", array_map(fn (int $n): string => "{$n}. Scan label {$n}.", range(1, 11)));

    $run = launchChecks(lengthyContent("## Steps\n\n{$steps}\n"))->run();

    expect($run->exitCode)->toBe(Command::SUCCESS)
        ->and($run->reports())->toBe([
            'articles/receive-a-delivery.md:22 [length.steps] a run of 11 steps, over the Style Guide\'s ten: split the task into parts, each under a heading',
        ]);
});

it('doesn\'t report a run of ten steps', function () {
    $steps = implode("\n", array_map(fn (int $n): string => "{$n}. Scan label {$n}.", range(1, 10)));

    $run = launchChecks(lengthyContent("{$steps}\n"))->run();

    expect(implode("\n", $run->reports()))->not->toContain('length.steps');
});

it('reports an Article over its template\'s guide length without failing', function () {
    $body = implode("\n\n", array_fill(0, 30, 'Scan the label on the box.'));

    $run = launchChecks(lengthyContent($body))
        ->templates(templates()->template('How-to', [['part' => 'In short', 'when_needed' => true]], guideWords: 150))
        ->run();

    expect($run->exitCode)->toBe(Command::SUCCESS)
        ->and($run->reports())->toBe([
            'articles/receive-a-delivery.md:1 [length.article] about 180 words, over the How-to guide of about 150',
        ]);
});

it('posts failures first and the reports for the reviewer in the pull request comment', function () {
    $steps = implode("\n", array_map(fn (int $n): string => "{$n}. Scan label {$n}.", range(1, 11)));

    $run = launchChecks(lengthyContent("{$steps}\n")->article('pack-an-order', ['product_area' => 'Outbound']))
        ->description('Help only: tidying')
        ->run(['--main-project-ref' => 'refs/pull/261/head']);

    expect($run->markdown)
        ->toContain('<!-- help-launch-checks -->')
        ->toContain('**Failed:** 1 failure to fix')
        ->toContain('- `articles/pack-an-order.md:5` **header.product_area**: "Outbound" is not on the Product Area list')
        ->toContain('<summary><strong>Reports</strong> (1): for the reviewer to judge; these never fail the build</summary>')
        ->toContain('- `articles/receive-a-delivery.md:20` **length.steps**: a run of 11 steps')
        ->toContain('Main project read at `refs/pull/261/head`');
});

it('says the checks passed when only reports were found', function () {
    $steps = implode("\n", array_map(fn (int $n): string => "{$n}. Scan label {$n}.", range(1, 11)));

    $run = launchChecks(lengthyContent("{$steps}\n"))->run();

    expect($run->exitCode)->toBe(Command::SUCCESS)
        ->and($run->markdown)->toContain('**Passed.** 1 report for the reviewer below.')
        ->not->toContain('### Failures');
});
