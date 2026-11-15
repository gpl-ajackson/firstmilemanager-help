<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FixedPictureTool;
use Tests\Support\FixtureContent;
use Tests\Support\FixtureMainProject;

/*
| The Release Check (spec #30, Release tooling; user stories 145 and 149; ADR-0003): before a
| FMM Release (a promotion of the main project to its demo branch), every main-project pull
| request merged since the last release with its help status, the draft Release Note, and the
| Launch Checks with every Picture retaken against the version being released, ending with
| whether help is complete for this release. Stockist is read only through its repository: the
| checkout's history and GitHub's API, both faked here (tests/Support/ReleaseCheckRun.php).
*/

const HELP_PROPOSAL_81 = "## Summary\n\nZone walks.\n\n## Help content\n\nhttps://github.com/gpl-ajackson/firstmilemanager-help/pull/81\n";

/** The main project's pull request template, left as it is: its comment names "No help changes: <reason>" but says none. */
const UNTOUCHED_TEMPLATE = "## Summary\n\nA fix.\n\n## Help content\n\n<!-- Link the support-site Help Proposal for this change (a gpl-ajackson/firstmilemanager-help pull request; for a spec's ticket, the spec's shared one), or write \"No help changes: <reason>\". The Help content check fails without one. See docs/agents/help-content.md. -->\n";

/** A content folder whose lists pass Launch Check 1, as the real content/ has them. */
function releasedContent(): FixtureContent
{
    return content()
        ->productAreas(array_flip(FixtureMainProject::MENUS))
        ->articleOwners(['gpl-ajackson']);
}

it('lists every pull request merged since the last FMM Release with its help status, and marks missing help', function () {
    $run = releaseCheck(content())
        ->mergedPullRequest(270, 'Released last time', "No help changes: tooling\n")
        ->stockistRelease()
        ->mergedPullRequest(275, 'Walk a zone by Location Type', HELP_PROPOSAL_81)
        ->helpProposal(81, 'merged')
        ->mergedPullRequest(276, 'Hold the clock for Pictures', "## Help content\n\nNo help changes: help centre tooling\n")
        ->mergedPullRequest(277, 'Fix the dock scanner', UNTOUCHED_TEMPLATE)
        ->run(['--date' => '2026-11-04']);

    expect($run->changes())->toBe([
        'OK       #275 Walk a zone by Location Type: Help Proposal gpl-ajackson/firstmilemanager-help#81, merged',
        'OK       #276 Hold the clock for Pictures: No help changes: help centre tooling',
        'MISSING  #277 Fix the dock scanner: no Help Proposal linked and no "No help changes: <reason>" in its description',
    ])
        ->and($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->verdict())->toStartWith('Help is not complete for this release:');
});

it('says plainly that help is complete when every change has its help merged or says "No help changes"', function () {
    $run = releaseCheck(content())
        ->mergedPullRequest(275, 'Walk a zone by Location Type', HELP_PROPOSAL_81)
        ->helpProposal(81, 'merged')
        ->mergedPullRequest(276, 'Bump Filament', '', author: 'dependabot[bot]')
        ->run();

    expect($run->exitCode)->toBe(Command::SUCCESS)
        ->and($run->changes())->toBe([
            'OK       #275 Walk a zone by Location Type: Help Proposal gpl-ajackson/firstmilemanager-help#81, merged',
            "OK       #276 Bump Filament: Dependabot's dependency update (exempt from the Help content check)",
        ])
        ->and($run->verdict())->toStartWith('Help is complete for this release: every change has its help merged or says "No help changes", and the Launch Checks pass against Stockist ')
        ->and($run->markdown)->toStartWith("<!-- help-release-check -->\n## Release Check: Stockist ")
        ->and($run->markdown)->toContain('**Help is complete for this release.**')
        ->and($run->markdown)->toContain('- **OK** [#275](https://github.com/gpl-ajackson/firstmilemanager/pull/275) Walk a zone by Location Type: Help Proposal [gpl-ajackson/firstmilemanager-help#81](https://github.com/gpl-ajackson/firstmilemanager-help/pull/81), merged');
});

it('holds the release for a Help Proposal still open, one closed without merging, and a link to no pull request', function () {
    $run = releaseCheck(content())
        ->mergedPullRequest(281, 'Enter an order', "Help: gpl-ajackson/firstmilemanager-help#90\n")
        ->helpProposal(90, 'open')
        ->mergedPullRequest(282, 'Carriers', "https://github.com/gpl-ajackson/firstmilemanager-help/pull/91\n")
        ->helpProposal(91, 'closed')
        ->mergedPullRequest(283, 'Service levels', "Help: gpl-ajackson/firstmilemanager-help#92 and gpl-ajackson/firstmilemanager-help#93\n")
        ->helpProposal(92, 'merged')
        ->mergedPullRequest(284, 'Calendar', "Help: gpl-ajackson/firstmilemanager-help#99\n")
        ->run();

    expect($run->changes())->toBe([
        'WAITING  #281 Enter an order: Help Proposal gpl-ajackson/firstmilemanager-help#90, still open: merge it before releasing',
        'MISSING  #282 Carriers: Help Proposal gpl-ajackson/firstmilemanager-help#91, closed without merging',
        "MISSING  #283 Service levels: Help Proposals gpl-ajackson/firstmilemanager-help#92, merged; gpl-ajackson/firstmilemanager-help#93, isn't a pull request GitHub shows in gpl-ajackson/firstmilemanager-help",
        "MISSING  #284 Calendar: Help Proposal gpl-ajackson/firstmilemanager-help#99, isn't a pull request GitHub shows in gpl-ajackson/firstmilemanager-help",
    ])
        ->and($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->verdict())->toBe('Help is not complete for this release: 3 changes have no help; 1 change waits for its Help Proposal to merge. Release when each is put right and this check passes.');
});

it('lists commits pushed straight to main, and finds the pull request of a merge whose commit doesn\'t name it', function () {
    $run = releaseCheck(content())
        ->pushedToMain("Increase modal size for rapid scanner.\n")
        ->pushedToMain("Fix a typo on the dock label\n\nNo help changes: wording help doesn't quote\n")
        ->mergedPullRequest(285, 'Rebased onto main', "No help changes: tests only\n", namedInCommit: false)
        ->run();

    $changes = $run->changes();
    expect($changes)->toHaveCount(3)
        ->and($changes[0])->toMatch('/\AMISSING  [0-9a-f]{12} Increase modal size for rapid scanner\.: pushed straight to main, and its commit message neither links a Help Proposal nor says "No help changes: <reason>"\z/')
        ->and($changes[1])->toMatch("/\\AOK       [0-9a-f]{12} Fix a typo on the dock label: No help changes: wording help doesn't quote\\z/")
        ->and($changes[2])->toBe('OK       #285 Rebased onto main: No help changes: tests only');
});

it('says nothing was merged since the last FMM Release', function () {
    $run = releaseCheck(content())
        ->mergedPullRequest(270, 'Released last time', "No help changes: tooling\n")
        ->stockistRelease()
        ->run();

    expect($run->changes())->toBe(['None: nothing was merged into main since the last FMM Release.'])
        ->and($run->exitCode)->toBe(Command::SUCCESS);
});

it('shows the draft Release Note exactly as help:release-note would publish it, writing nothing', function () {
    $content = releasedContent()
        ->article('book-in-a-shipment', ['title' => 'Book in a shipment'])
        ->changeSummary('stockist-215-delivery-numbers', ['product_area' => 'Inbound', 'affects_clients' => true, 'articles' => ['book-in-a-shipment']],
            "Delivery numbers now carry the warehouse they belong to, such as `INB-W1-000023`.\n")
        ->changeSummary('stockist-215-warehouse-codes', ['product_area' => 'Warehouses'],
            "Each warehouse has a Warehouse Code: 1 to 4 capital letters or digits.\n");

    $run = releaseCheck($content)->run(['--date' => '2026-11-04']);

    expect(glob($content->path().'/release-notes/*'))->toBe([])
        ->and(glob($content->path().'/next-release/*.md'))->toHaveCount(2)
        ->and($run->output)->toContain('Draft Release Note (release-notes/2026-11-04.md, from 2 Change Summaries: Warehouses, Inbound):');

    Artisan::call('help:release-note', ['--content' => $content->path(), '--date' => '2026-11-04']);
    $published = array_values(array_filter(explode("\n", (string) file_get_contents($content->path().'/release-notes/2026-11-04.md')), fn (string $line): bool => trim($line) !== ''));

    expect($run->section('Draft Release Note'))->toBe($published)
        ->and($run->markdown)->toContain("```markdown\n".file_get_contents($content->path().'/release-notes/2026-11-04.md'));
});

it('holds the release when a Change Summary is one the rebuild would refuse', function () {
    $run = releaseCheck(content()->changeSummary('stockist-216-billing', ['affects_clients' => null]))->run();

    expect($run->section('Draft Release Note')[0])->toBe('The Next Release folder has Change Summaries the rebuild would refuse:')
        ->and($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->verdict())->toContain('the Next Release folder has Change Summaries the rebuild would refuse');
});

it('runs the content checks against the version being released and holds the release for a failure', function () {
    $content = releasedContent()->article('add-a-location', [], "1. Read [zones](article:no-such-article) first.\n");

    $run = releaseCheck($content)->run();

    expect(implode("\n", $run->section('Launch Checks against the version being released')))
        ->toContain('FAIL articles/add-a-location.md:20 [links.resolves]')
        ->and($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->verdict())->toBe('Help is not complete for this release: 1 other Launch Check failure. Release when each is put right and this check passes.');
});

it('retakes every Picture from the version being released and lists one that differs', function () {
    $content = releasedContent()
        ->picture('warehouses-locations-list', ['articles' => ['add-a-location']])
        ->picture('clients-list', ['starts_on' => 'Clients', 'articles' => ['add-a-location']])
        ->article('add-a-location', [], "1. Open Warehouses › Locations.\n\n   ![](picture:warehouses-locations-list)\n\n2. Open Clients.\n\n   ![](picture:clients-list)\n");
    $changed = imagecreatefrompng($content->picturesPath().'/warehouses-locations-list.png');
    imagefilledrectangle($changed, 600, 100, 900, 300, (int) imagecolorallocate($changed, 20, 90, 200));
    ob_start();
    imagepng($changed);
    $tool = FixedPictureTool::taking($content->picturesPath(), ['warehouses-locations-list' => (string) ob_get_clean()]);

    $run = releaseCheck($content)->run();

    expect($tool->asked)->toBe([['repository' => $run->mainProjectPath(), 'ref' => 'HEAD', 'recipes' => ['clients-list', 'warehouses-locations-list']]])
        ->and($run->section('Pictures that differ at the version being released'))->toHaveCount(1)
        ->and($run->section('Pictures that differ at the version being released')[0])
        ->toStartWith("FAIL pictures/warehouses-locations-list.png [pictures.retaken] isn't what its Recipe takes from Stockist 555555555555 at the Picture Date: ")
        ->and($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->verdict())->toBe('Help is not complete for this release: 1 Picture differs at the version being released. Release when each is put right and this check passes.')
        ->and($run->markdown)->toContain("The before, after and difference images are in the run's `picture-check` artefact.");
});

it('reads Stockist only through its repository: the checkout and GitHub, with the read-only key for the main project', function () {
    $run = releaseCheck(content())
        ->mergedPullRequest(275, 'Walk a zone by Location Type', HELP_PROPOSAL_81)
        ->helpProposal(81, 'merged')
        ->mergedPullRequest(285, 'Rebased onto main', "No help changes: tests only\n", namedInCommit: false)
        ->run();

    expect($run->requests)->toHaveCount(4)
        ->and(array_filter($run->requests, fn (string $request): bool => preg_match('#\AGET https://api\.github\.com/repos/gpl-ajackson/firstmilemanager/(pulls/\d+|commits/[0-9a-f]{40}/pulls) \(fixture-read-token\)\z#', $request) !== 1
            && preg_match('#\AGET https://api\.github\.com/repos/gpl-ajackson/firstmilemanager-help/pulls/\d+ \(fixture-workflow-token\)\z#', $request) !== 1))->toBe([]);
});

it('marks every change unread, and help not complete, without the main project\'s read-only key', function () {
    $run = releaseCheck(content())
        ->mergedPullRequest(275, 'Walk a zone by Location Type', HELP_PROPOSAL_81)
        ->withoutStockistToken()
        ->run();

    expect($run->changes())->toBe([
        "UNREAD   #275 Walk a zone by Location Type: its help status couldn't be read: FMM_READ_TOKEN is not set, so the main project's pull requests on GitHub can't be read",
    ])
        ->and($run->requests)->toBe([])
        ->and($run->exitCode)->toBe(Command::FAILURE)
        ->and($run->verdict())->toContain("1 change's help status couldn't be read");
});

it('never passes a change GitHub couldn\'t be asked about', function () {
    $run = releaseCheck(content())
        ->mergedPullRequest(275, 'Walk a zone by Location Type', HELP_PROPOSAL_81)
        ->githubUnreachable()
        ->run();

    expect($run->changes())->toBe([
        "UNREAD   #275 Walk a zone by Location Type: its help status couldn't be read: GitHub answered 502 for gpl-ajackson/firstmilemanager/pulls/275",
    ])
        ->and($run->exitCode)->toBe(Command::FAILURE);
});

it('can\'t start without the last FMM Release in the checkout, and takes another one with --last-release', function () {
    $run = releaseCheck(content())
        ->withoutDemoBranch()
        ->mergedPullRequest(275, 'Walk a zone by Location Type', HELP_PROPOSAL_81)
        ->helpProposal(81, 'merged')
        ->run();

    expect($run->exitCode)->toBe(Command::INVALID)
        ->and($run->output)->toContain("The Release Check can't start: the main project's checkout at ")
        ->and($run->output)->toContain('has no origin/demo.')
        ->and($run->output)->toContain('Before the first promotion to demo, give the commit last released with --last-release.');

    $first = releaseCheck(content())
        ->withoutDemoBranch()
        ->mergedPullRequest(275, 'Walk a zone by Location Type', HELP_PROPOSAL_81)
        ->helpProposal(81, 'merged')
        ->run(['--last-release' => 'HEAD^1']);

    expect($first->exitCode)->toBe(Command::SUCCESS)
        ->and($first->changes())->toBe(['OK       #275 Walk a zone by Location Type: Help Proposal gpl-ajackson/firstmilemanager-help#81, merged']);
});

it('holds the release when the help centre\'s facts differ from the marketing site\'s site.ts at main', function () {
    $run = releaseCheck(releasedContent()->facts(['stage' => 'Beta']))
        ->marketingSite(marketingSite())
        ->run();

    expect(implode("\n", $run->section('Launch Checks against the version being released')))
        ->toContain('FAIL facts.yaml:3 [indexing.facts] stage is "Beta" here but "Early access" in the marketing site\'s src/data/site.ts: copy it from there')
        ->and($run->exitCode)->toBe(Command::FAILURE);
});

it('says the facts weren\'t compared without a checkout of the marketing site', function () {
    $run = releaseCheck(releasedContent())->withoutMarketingSite()->run();

    expect(implode("\n", $run->section('Launch Checks against the version being released')))
        ->toContain('Not run: Indexing and facts, the facts against the marketing site\'s src/data/site.ts: give --marketing-site=')
        ->not->toContain('[indexing.facts]');
});
