<?php

use App\Pictures\BrokenRecipe;
use App\Pictures\TakenPicture;
use Tests\Support\FakeStockist;

/*
 * The camera on a busy server (fmm#432). The CI server's Picture Check broke
 * pack-station-packing, pack-station-labels and packaging-items now and then, on a 30-second
 * wait, while a local retake of the same Recipe came back the same. Two things raced the camera
 * there: the next page after a menu link still on its way when the next step looked for its
 * button, and a page's fields showing before Livewire had started on it, so what was typed was
 * never seen and Enter sent an empty scan. The camera now waits for both.
 */

function busyRecipe(array $fields): array
{
    return [
        'signs_in_as' => 'Dan Price',
        'device' => 'desk',
        'keep' => 'screen',
        'outline' => null,
        'alt' => 'A page of the fake Stockist after the Recipe has reached it.',
        'articles' => ['pack-an-order'],
        ...$fields,
    ];
}

function busyWhyNot(TakenPicture|BrokenRecipe $result): string
{
    return $result instanceof BrokenRecipe ? (string) $result : '';
}

/**
 * A page like the Pack Station: a Scan field and an Enter button in a Livewire component that
 * starts 1.5 seconds after the page has loaded. Until then the form isn't Livewire's, and what
 * Livewire starts with (an empty field) replaces anything typed before it.
 */
const LATE_LIVEWIRE = <<<'HTML'
    <div wire:id="bench">
        <form id="bench-form" action="" method="get">
            <label for="scan">Scan</label> <input id="scan" type="text">
            <button type="submit">Enter</button>
        </form>
        <p id="said"></p>
    </div>
    <script>
        window.addEventListener('load', () => setTimeout(() => {
            const bench = document.querySelector('[wire\\:id]');
            const scan = document.getElementById('scan');
            let code = '';
            scan.value = '';
            scan.addEventListener('input', () => { code = scan.value; });
            document.getElementById('bench-form').addEventListener('submit', (event) => {
                event.preventDefault();
                document.getElementById('said').textContent = code === '' ? 'Scan something first' : `Bench open at ${code}`;
            });
            window.Livewire = {};
            bench.__livewire = {};
        }, 1500));
    </script>
    HTML;

it('waits for Livewire to start on a page before typing into it', function () {
    $stockist = FakeStockist::start()
        ->page('Dashboard', '<h1>Dashboard</h1>')
        ->page('Pack Station', LATE_LIVEWIRE);

    $picture = $stockist->take(recipes()->recipe('busy-bench', busyRecipe([
        'starts_on' => 'Pack Station',
        'steps' => [
            ['enter' => ['Scan' => 'COL-DS-PACK-02']],
            ['select' => 'Enter'],
            ['wait_for' => 'Bench open at COL-DS-PACK-02'],
        ],
    ])), 'busy-bench');

    expect($picture)->toBeInstanceOf(TakenPicture::class, busyWhyNot($picture));
});

it('waits for the next page after a link, however slowly it comes, before looking for a button on it', function () {
    $stockist = FakeStockist::start()
        ->page('Dashboard', '<h1>Dashboard</h1>')
        ->page('Packaging Items', '<h1>Packaging Items</h1><button type="button" onclick="this.textContent = \'Added\'">Add a Packaging Item</button>', answersAfterMs: 2500);

    $picture = $stockist->take(recipes()->recipe('busy-menu', busyRecipe([
        'starts_on' => 'Dashboard',
        'steps' => [
            ['go_to' => 'Packaging Items'],
            ['select' => 'Add a Packaging Item'],
            ['wait_for' => 'Added'],
        ],
    ])), 'busy-menu');

    expect($picture)->toBeInstanceOf(TakenPicture::class, busyWhyNot($picture));
});

it('looks again for a button that shows a moment after the step before', function () {
    $stockist = FakeStockist::start()->page('Dashboard', <<<'HTML'
        <button type="button" onclick="setTimeout(() => { document.getElementById('later').hidden = false; }, 2000)">Open the rapid scanner</button>
        <div id="later" hidden><button type="button" onclick="this.textContent = 'Opened'">Next List</button></div>
        HTML);

    $picture = $stockist->take(recipes()->recipe('busy-later', busyRecipe([
        'starts_on' => 'Dashboard',
        'steps' => [
            ['select' => 'Open the rapid scanner'],
            ['select' => 'Next List'],
            ['wait_for' => 'Opened'],
        ],
    ])), 'busy-later');

    expect($picture)->toBeInstanceOf(TakenPicture::class, busyWhyNot($picture));
});

it('scans into the Rapid Scanner\'s scan field by either of its labels', function (string $label) {
    $stockist = FakeStockist::start()->page('Dashboard', <<<HTML
        <form onsubmit="event.preventDefault(); fetch('/scan', { method: 'POST' }).then(() => { document.getElementById('said').textContent = 'Scanned ' + document.getElementById('code').value; })">
            <label for="code">{$label}</label> <input id="code" type="text">
        </form>
        <p id="said"></p>
        HTML);

    $picture = $stockist->take(recipes()->recipe('busy-scan', busyRecipe([
        'starts_on' => 'Dashboard',
        'device' => 'handheld',
        'steps' => [
            ['scan' => 'COL-PF-P03B3'],
            ['wait_for' => 'Scanned COL-PF-P03B3'],
        ],
    ])), 'busy-scan');

    expect($picture)->toBeInstanceOf(TakenPicture::class, busyWhyNot($picture));
})->with(['the console' => 'Scan or type a code', 'the scanner before it' => 'Scanned code']);

it('selects a choice drawn as buttons, whose radio the pointer can\'t reach, by its label', function () {
    $stockist = FakeStockist::start()->page('Dashboard', <<<'HTML'
        <fieldset>
            <legend>Sound</legend>
            <input id="sound-off" name="sound" type="radio" value="off" style="pointer-events: none; opacity: 0; position: absolute">
            <label for="sound-off" onclick="document.getElementById('said').textContent = 'Sound off'">Off</label>
            <input id="sound-loud" name="sound" type="radio" value="loud" checked style="pointer-events: none; opacity: 0; position: absolute">
            <label for="sound-loud">Loud</label>
        </fieldset>
        <p id="said"></p>
        HTML);

    $picture = $stockist->take(recipes()->recipe('busy-choice', busyRecipe([
        'starts_on' => 'Dashboard',
        'steps' => [
            ['select' => 'Off'],
            ['wait_for' => 'Sound off'],
        ],
    ])), 'busy-choice');

    expect($picture)->toBeInstanceOf(TakenPicture::class, busyWhyNot($picture));
});

it('looks in the whole section a heading names, not only the section\'s own header', function () {
    $stockist = FakeStockist::start()->page('Dashboard', <<<'HTML'
        <section>
            <header><h2>Profile Information</h2></header>
            <button type="button" onclick="document.getElementById('said').textContent = 'Profile saved'">Save</button>
        </section>
        <section>
            <header><h2>Sound and Vibration</h2><p>How each scan's result sounds.</p></header>
            <button type="button" onclick="document.getElementById('said').textContent = 'Sound and Vibration saved'">Save</button>
        </section>
        <p id="said"></p>
        HTML);

    $picture = $stockist->take(recipes()->recipe('busy-section', busyRecipe([
        'starts_on' => 'Dashboard',
        'steps' => [
            ['select' => 'Save', 'in' => 'Sound and Vibration'],
            ['wait_for' => 'Sound and Vibration saved'],
        ],
    ])), 'busy-section');

    expect($picture)->toBeInstanceOf(TakenPicture::class, busyWhyNot($picture));
});

it('leaves the page where it was when a button on the screen is pressed a moment late', function () {
    // Load and close out's Scan button, still disabled while the answer to the step before comes
    // back: Playwright tried again and again, scrolling the button to the bottom, the middle and
    // the top of the screen in turn, so the Picture showed the page wherever the try that worked
    // had left it (fmm#432's Picture Check).
    $stockist = FakeStockist::start()->page('Dashboard', <<<'HTML'
        <h1 style="height: 300px">Load and close out</h1>
        <button id="scan" type="button" disabled onclick="document.getElementById('said').textContent = 'Pressed at ' + Math.round(window.scrollY)">Scan</button>
        <p id="said"></p>
        <div style="height: 2000px"></div>
        <script>
            window.addEventListener('load', () => setTimeout(() => { document.getElementById('scan').disabled = false; }, 1500));
        </script>
        HTML);

    $picture = $stockist->take(recipes()->recipe('busy-late-button', busyRecipe([
        'starts_on' => 'Dashboard',
        'device' => 'handheld',
        'steps' => [
            ['select' => 'Scan'],
            ['wait_for' => 'Pressed at'],
            ['wait_for' => 'Pressed at 0'],
        ],
    ])), 'busy-late-button');

    expect($picture)->toBeInstanceOf(TakenPicture::class, busyWhyNot($picture));
});
