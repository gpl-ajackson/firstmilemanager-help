<?php

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Tests\Support\DriftReportRun;
use Tests\Support\FixtureRecipes;

/*
 * The Help Drift Report (spec #30, user stories 148 and 149; plan S7 step 9): on the first
 * working day of each month a workflow in this repository raises one issue for the Content
 * Owner listing where help may have fallen behind Stockist. It is tested from fixture
 * repositories: a fixture content folder, a fixture main-project checkout with its git history,
 * and GitHub faked at its boundary (seam 4). Stockist is read only through its repository.
 */

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-12-01 07:00', 'Europe/London')));

const DRIFT_SECTIONS = [
    'Articles whose sources changed',
    'Source References that don\'t match both ways',
    'Main-project changes merged without their help',
    'Articles past their review date',
    'Pictures that differ from',
    'The Glossary Check\'s reports',
];

it('raises one issue for the Content Owner, labelled for the Help Gaps Report, saying "None this month" for each empty section', function () {
    $run = driftReport(content()->article('receive-a-delivery'))->run(['--take-pictures' => true]);

    $issue = $run->issue();
    expect($run->exitCode)->toBe(Command::SUCCESS, $run->output)
        ->and($issue['title'])->toBe('Help drift: November 2026')
        ->and($issue['assignees'])->toBe(['gpl-ajackson'])
        ->and($issue['labels'])->toBe(['help-drift-report'])
        ->and($issue['body'])->toStartWith("<!-- help-drift-report:2026-11 -->\n")
        ->and($run->output)->toContain('Raised the Help Drift Report for November 2026: https://github.com/gpl-ajackson/firstmilemanager-help/issues/1');
    foreach (DRIFT_SECTIONS as $section) {
        expect($run->section($section))->toBe(['None this month.']);
    }
});

it('lists Published Articles whose review date has come, linking the date in the file', function () {
    $content = content()
        ->article('receive-a-delivery', ['checked_on' => '2026-06-01', 'next_review_on' => '2026-11-30'])
        ->article('count-a-location', ['checked_on' => '2026-06-01', 'next_review_on' => '2026-12-01', 'owner' => 'gpl-ajackson'])
        ->article('put-away-stock', ['checked_on' => '2026-06-02', 'next_review_on' => '2026-12-02'])
        ->article('an-old-draft', ['status' => 'Draft', 'checked_on' => '2026-05-01', 'next_review_on' => '2026-11-01']);

    $run = driftReport($content)->run();

    expect($run->section('Articles past their review date'))->toBe([
        '[`articles/count-a-location.md`](https://github.com/gpl-ajackson/firstmilemanager-help/blob/main/content/articles/count-a-location.md#L10): its review was due on 1 December 2026 (owner `gpl-ajackson`)',
        '[`articles/receive-a-delivery.md`](https://github.com/gpl-ajackson/firstmilemanager-help/blob/main/content/articles/receive-a-delivery.md#L10): its review was due on 30 November 2026 (owner `gpl-ajackson`)',
    ]);
});

const RECEIVING_PLAN = '.junie/plans/Inbound/receiving.md';

it('lists Articles whose plan, ADR or spec changed after their Checked On, but not a Help Proposal listing its Articles', function () {
    $project = mainProject()
        ->plan(RECEIVING_PLAN, "- /articles/receive-a-delivery\n- /articles/put-away-stock", "# Receiving\n\nA delivery is booked in, then received.\n")
        ->adr(2, 'locations-nest')
        ->issue(87, 'Spec: Receiving', "## Help content\n\n- /articles/receive-a-delivery", editedAt: '2026-11-07T14:00:00Z')
        ->issue(88, 'Spec: Put-away', "## Help content\n\n- /articles/receive-a-delivery", editedAt: '2026-10-24T09:00:00Z')
        ->commit('Plan receiving', '2026-09-01 10:00')
        ->plan(RECEIVING_PLAN, "- /articles/receive-a-delivery\n- /articles/put-away-stock", "# Receiving\n\nA delivery is booked in, then received and checked.\n")
        ->commit('Receiving: say it is checked', '2026-10-24 15:00')
        ->plan(RECEIVING_PLAN, "- /articles/receive-a-delivery\n- /articles/put-away-stock\n- /articles/count-a-location", "# Receiving\n\nA delivery is booked in, then received and checked.\n")
        ->commit('Help content: list count-a-location', '2026-11-03 11:00');
    $helpOnly = $project->head();
    $project
        ->plan(RECEIVING_PLAN, "- /articles/receive-a-delivery\n- /articles/put-away-stock\n- /articles/count-a-location", "# Receiving\n\nA delivery is booked in at the dock, then received and checked.\n")
        ->commit('Receiving at the dock (@someone, #12)', '2026-11-10 16:30');
    $dock = $project->head();
    $project
        ->file('docs/adr/0002-locations-nest.md', "# 2. Locations nest\n\nNow they nest four deep.\n")
        ->commit('ADR-0002: four deep', '2026-11-05 09:00');
    $adr = $project->head();

    $content = content()
        ->article('receive-a-delivery', ['checked_on' => '2026-10-24', 'source_references' => [RECEIVING_PLAN, 'ADR-0002', 'fmm#87', 'fmm#88']])
        ->article('put-away-stock', ['checked_on' => '2026-11-20', 'source_references' => [RECEIVING_PLAN]])
        ->article('count-a-location', ['checked_on' => '2026-11-06', 'source_references' => ['ADR-0002', 'ADR-0009']])
        ->article('a-retired-one', ['status' => 'Retired', 'what_changed' => 'Gone.', 'checked_on' => '2026-01-01', 'source_references' => [RECEIVING_PLAN]]);

    $run = driftReport($content)->against($project)->run();

    $commit = $project->head();
    $article = '[`articles/receive-a-delivery.md`](https://github.com/gpl-ajackson/firstmilemanager-help/blob/main/content/articles/receive-a-delivery.md) (Checked On 24 October 2026)';
    expect($run->section('Articles whose sources changed'))->toBe([
        "{$article} cites [`".RECEIVING_PLAN."`](https://github.com/gpl-ajackson/firstmilemanager/commits/{$commit}/".RECEIVING_PLAN.'): 2 commits since, the latest '
            .'[`'.substr($dock, 0, 7)."`](https://github.com/gpl-ajackson/firstmilemanager/commit/{$dock}) on 10 November 2026 (`Receiving at the dock (@someone, #12)`)",
        "{$article} cites [`ADR-0002`](https://github.com/gpl-ajackson/firstmilemanager/commits/{$commit}/docs/adr/0002-locations-nest.md): 1 commit since, the latest "
            .'[`'.substr($adr, 0, 7)."`](https://github.com/gpl-ajackson/firstmilemanager/commit/{$adr}) on 5 November 2026 (`ADR-0002: four deep`)",
        "{$article} cites [fmm#87](https://github.com/gpl-ajackson/firstmilemanager/issues/87): its description was edited on 7 November 2026",
    ])->and($helpOnly)->not->toBe($dock);
});

it('leaves out a plan whose only change since the Checked On is its Help content section', function () {
    $project = mainProject()
        ->plan(RECEIVING_PLAN, '- /articles/receive-a-delivery', "# Receiving\n\nA delivery is booked in.\n")
        ->commit('Plan receiving', '2026-09-01 10:00')
        ->plan(RECEIVING_PLAN, "- /articles/receive-a-delivery\n- /articles/put-away-stock", "# Receiving\n\nA delivery is booked in.\n")
        ->commit('Help content: list put-away-stock', '2026-11-03 11:00');

    $run = driftReport(content()->article('receive-a-delivery', ['source_references' => [RECEIVING_PLAN]]))->against($project)->run();

    expect($run->section('Articles whose sources changed'))->toBe(['None this month.']);
});

it('says the sources weren\'t checked when the main project\'s checkout has no history, and when issues can\'t be read', function () {
    $content = content()->article('receive-a-delivery', ['source_references' => ['ADR-0001', 'fmm#87']]);
    $project = mainProject()->issue(87, 'Spec: Receiving', "## Help content\n\n- /articles/receive-a-delivery");
    $project->commit('Start', '2026-09-01 10:00');
    Process::path($project->path())->run(['git', 'clone', '--quiet', '--depth=1', 'file://'.$project->path(), 'shallow'])->throw();
    $shallow = $project->path().'/shallow';

    $run = driftReport($content)->against($project)->withoutStockistToken()->run(['--main-project' => $shallow]);

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->section('Articles whose sources changed'))->toBe([
            '_Not checked:_ the plans, ADRs and other files Articles cite: the main project\'s checkout is shallow, so its history can\'t be read: check it out with its whole history (fetch-depth: 0).',
            '_Not checked:_ the main project\'s issues Articles cite (fmm#87): there is no FMM_READ_TOKEN to read them from GitHub with.',
        ]);
});

it('lists Source References that don\'t match both ways or lead nowhere, each linked where it is', function () {
    $project = mainProject()
        ->plan(RECEIVING_PLAN, '- /articles/gone-missing')
        ->issue(87, 'Spec: Receiving', "## Help content\n\n- /articles/put-away-stock")
        ->commit('Start', '2026-09-01 10:00');
    $content = content()->article('receive-a-delivery', ['source_references' => [RECEIVING_PLAN, 'ADR-0007', 'fmm#87']]);

    $run = driftReport($content)->against($project)->run();

    $file = 'https://github.com/gpl-ajackson/firstmilemanager-help/blob/main/content/articles/receive-a-delivery.md';
    expect($run->section('Source References that don\'t match both ways'))->toBe([
        "[`articles/receive-a-delivery.md:14`]({$file}#L14) **sources.listed-back**: \"".RECEIVING_PLAN.'" doesn\'t list /articles/receive-a-delivery in its Help content section',
        "[`articles/receive-a-delivery.md:15`]({$file}#L15) **sources.exists**: \"ADR-0007\": the main project has no ADR numbered 0007 (docs/adr/0007-*.md)",
        "[`articles/receive-a-delivery.md:16`]({$file}#L16) **sources.listed-back**: \"fmm#87\" doesn't list /articles/receive-a-delivery in its Help content section",
        '[`fmm:'.RECEIVING_PLAN.':7`](https://github.com/gpl-ajackson/firstmilemanager/blob/'.$project->head().'/'.RECEIVING_PLAN.'#L7) **sources.listed**: Help content lists "/articles/gone-missing": there is no Article named "gone-missing" (this fails at launch)',
        '[`fmm#87`](https://github.com/gpl-ajackson/firstmilemanager/issues/87) **sources.listed**: Help content lists "/articles/put-away-stock": there is no Article named "put-away-stock" (this fails at launch)',
    ]);
});

it('lists what the Glossary Check reports, linked to the Term Status list and the entries', function () {
    $project = mainProject()
        ->glossary(['Location' => 'A place stock is kept, now with a barcode.', 'Dock Override' => 'Letting a delivery in outside its booking.'])
        ->commit('Start', '2026-09-01 10:00');
    $content = content()
        ->termStatus(['Location' => ['status' => 'Published', 'product_area' => 'Warehouses', 'fingerprint' => '6974c41e29f4']])
        ->glossaryEntry('location')
        ->article('receive-a-delivery');

    $run = driftReport($content)->against($project)->run();

    $items = $run->section('The Glossary Check\'s reports');
    expect($items)->toHaveCount(2)
        ->and($items[1])->toStartWith('[`term-status.yaml:1`](https://github.com/gpl-ajackson/firstmilemanager-help/blob/main/content/term-status.yaml#L1) **glossary.definition**: GLOSSARY.md\'s definition of "Location" has changed since it was last checked')
        ->and($items[0])->toStartWith('[`term-status.yaml`](https://github.com/gpl-ajackson/firstmilemanager-help/blob/main/content/term-status.yaml) **glossary.term-status**: "Dock Override" has no Term Status');
});

it('lists the month\'s merged main-project changes whose Help Proposal isn\'t merged, or that carry no help line', function () {
    $template = "## Summary\n\nA change.\n\n## Help content\n\n<!-- Link the support-site Help Proposal for this change, or write \"No help changes: <reason>\". -->\n";
    $project = mainProject()
        ->mergedPullRequest(290, 'Book deliveries into Receiving Slots', "Help: https://github.com/gpl-ajackson/firstmilemanager-help/pull/77\n", '2026-11-04 10:00')
        ->mergedPullRequest(291, 'Rename a Location', "Help Proposal: gpl-ajackson/firstmilemanager-help#78\n", '2026-11-05 10:00')
        ->mergedPullRequest(292, 'Tidy the importer', "No help changes: internal refactor\n", '2026-11-06 10:00')
        ->mergedPullRequest(293, 'Quick fix for @someone', $template, '2026-11-07 10:00')
        ->mergedPullRequest(294, 'Bump laravel/framework', 'Bumps laravel/framework.', '2026-11-08 10:00', author: 'dependabot[bot]')
        ->mergedPullRequest(295, 'Count a Location twice', "https://github.com/gpl-ajackson/firstmilemanager-help/pull/79\n", '2026-11-09 10:00')
        ->mergedPullRequest(296, 'Late in October', 'Neither.', '2026-10-31 23:30')
        ->mergedPullRequest(297, 'Early in December', 'Neither.', '2026-12-01 00:30')
        ->mergedPullRequest(298, 'Hidden link', "<!-- https://github.com/gpl-ajackson/firstmilemanager-help/pull/80 -->\n", '2026-11-30 23:30');

    $run = driftReport(content()->article('receive-a-delivery'))->against($project)
        ->helpProposal(77, 'open')->helpProposal(78, 'merged')->helpProposal(79, 'closed')->helpProposal(80, 'merged')
        ->run();

    expect($run->section('Main-project changes merged without their help'))->toBe([
        '[fmm#290](https://github.com/gpl-ajackson/firstmilemanager/pull/290) `Book deliveries into Receiving Slots`, merged on 4 November 2026: its [Help Proposal #77](https://github.com/gpl-ajackson/firstmilemanager-help/pull/77) is still open',
        '[fmm#293](https://github.com/gpl-ajackson/firstmilemanager/pull/293) `Quick fix for @someone`, merged on 7 November 2026: its description neither links a Help Proposal nor says `No help changes: <reason>`',
        '[fmm#295](https://github.com/gpl-ajackson/firstmilemanager/pull/295) `Count a Location twice`, merged on 9 November 2026: its [Help Proposal #79](https://github.com/gpl-ajackson/firstmilemanager-help/pull/79) was closed without merging',
        '[fmm#298](https://github.com/gpl-ajackson/firstmilemanager/pull/298) `Hidden link`, merged on 30 November 2026: its description neither links a Help Proposal nor says `No help changes: <reason>`',
    ]);
});

it('says the merged changes weren\'t checked without the main project\'s read-only key', function () {
    $run = driftReport(content()->article('receive-a-delivery'))->withoutStockistToken()->run();

    expect($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->section('Main-project changes merged without their help'))->toBe([
            '_Not checked:_ there is no FMM_READ_TOKEN to read the main project\'s pull requests with.',
        ])
        ->and($run->output)->toContain('Part of the report couldn\'t be checked');
});

it('lists the Picture Check\'s findings, linking the run that holds its images, when every Picture is retaken', function () {
    config(['help.pictures.work_path' => FixtureRecipes::folder('help-pictures-work')]);
    $content = content()
        ->picture('warehouses-locations-list', ['articles' => ['add-a-location']])
        ->article('add-a-location', [], "1. Open Warehouses › Locations.\n\n   ![](picture:warehouses-locations-list)\n");

    // The fixture main project is no Stockist, so no Stockist can be installed from it to take them.
    $run = driftReport($content)->run(['--take-pictures' => true]);

    $items = $run->section('Pictures that differ from `main`');
    expect($items)->toHaveCount(1)
        ->and($items[0])->toStartWith('[`pictures`]('.DriftReportRun::RUN_URL.') **pictures.stockist**: The Pictures to check (warehouses-locations-list) couldn\'t be retaken: ');
});

it('says the Pictures weren\'t compared when the run doesn\'t retake them', function () {
    $run = driftReport(content()->article('receive-a-delivery'))->run();

    expect($run->section('Pictures that differ from `main`'))->toBe([
        '_Not checked:_ Pictures compared with what their Recipes take from Stockist (give --take-pictures).',
    ]);
});

it('adds a second run in the same month to that month\'s issue, open or closed, rather than raising another', function () {
    $content = content()->article('receive-a-delivery', ['next_review_on' => '2026-11-30', 'checked_on' => '2026-06-01']);
    $first = driftReport($content)->run(['--take-pictures' => true]);
    $first->github->close(1);

    $this->travelTo(CarbonImmutable::parse('2026-12-03 15:00', 'Europe/London'));
    $again = driftReport($content)->onGitHub($first->github)->run(['--take-pictures' => true]);

    $issues = $first->github->issues();
    expect($issues)->toHaveCount(1)
        ->and($issues[0]['comments'])->toHaveCount(1)
        ->and($issues[0]['comments'][0])->toStartWith("## Run again on 3 December 2026\n\n")
        ->and($again->output)->toContain('Added again to the Help Drift Report for November 2026: https://github.com/gpl-ajackson/firstmilemanager-help/issues/1#issuecomment-1');
});

it('reports on the month asked for', function () {
    $run = driftReport(content()->article('receive-a-delivery'))->run(['--month' => '2026-10', '--take-pictures' => true]);

    expect($run->issue()['title'])->toBe('Help drift: October 2026')
        ->and($run->issue()['body'])->toStartWith("<!-- help-drift-report:2026-10 -->\n");
});
