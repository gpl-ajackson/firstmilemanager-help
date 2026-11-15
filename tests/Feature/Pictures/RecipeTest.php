<?php

use App\Pictures\PictureTool;

/*
 * Picture Recipes are read and checked before the picture tool goes near Stockist, so a writer
 * hears about a mistake in seconds, named by file and field, as help:rebuild names an Article's.
 * The slow tests that take Pictures are in tests/Pictures (php artisan test --testsuite=Pictures).
 */

it('refuses a Recipe that breaks a rule, naming the file and field, without starting Stockist', function (array $fields, string $problem) {
    $run = recipes()
        ->recipe('warehouses-locations-list', $fields)
        ->take(['--stockist' => '/nowhere/stockist']);

    expect($run['exitCode'])->toBe(1)
        ->and($run['output'])->toContain("INVALID warehouses-locations-list.yaml: {$problem}")
        ->and($run['output'])->not->toContain('/nowhere/stockist');
})->with([
    'no one signs in' => [['signs_in_as' => null], 'signs_in_as: is missing'],
    'no starting screen' => [['starts_on' => null], 'starts_on: is missing'],
    'an unknown device' => [['device' => 'tablet'], 'device: must be desk or handheld'],
    'nothing kept' => [['keep' => []], 'keep: must be "screen" or the words of what to keep'],
    'no alt text' => [['alt' => null], 'alt: is missing'],
    'alt text over 125 characters' => [['alt' => str_repeat('The Locations list. ', 7)], 'alt: is 139 characters; keep it to 125'],
    'alt text that says "Screenshot of"' => [['alt' => 'Screenshot of the Locations list.'], 'alt: must not start "Screenshot", "Image" or "Picture"'],
    'no Articles' => [['articles' => []], 'articles: list the Articles that use this Picture'],
    'an Article Name that is not one' => [['articles' => ['Add a location']], 'articles: "Add a location" is not an Article Name'],
    'a misspelt field' => [['outlines' => 'New Location'], 'outlines: is not a Recipe field'],
    'a step the tool cannot do' => [['steps' => [['click' => 'New Location']]], 'steps: step 1 must be one of go_to, select, enter, scan, wait_for'],
    'a step doing two things' => [['steps' => [['select' => 'New Location', 'enter' => ['Name' => 'Bay 1']]]], 'steps: step 1 must do one thing'],
    'entering into no field' => [['steps' => [['enter' => 'Bay 1']]], 'steps: step 1 enter must name one field and what to type, as "enter: {Name: Bay 1}"'],
]);

it('refuses a Picture Name that is not lower case words joined by hyphens', function () {
    $run = recipes()
        ->recipe('Warehouses_Locations', [])
        ->take(['--stockist' => '/nowhere/stockist']);

    expect($run['exitCode'])->toBe(1)
        ->and($run['output'])->toContain('INVALID Warehouses_Locations.yaml: Picture Name: use lower case letters, digits and single hyphens');
});

it('refuses a Recipe that is not YAML', function () {
    $run = recipes()
        ->file('warehouses-locations-list.yaml', "signs_in_as: [Sarah\n")
        ->take(['--stockist' => '/nowhere/stockist']);

    expect($run['exitCode'])->toBe(1)
        ->and($run['output'])->toContain('INVALID warehouses-locations-list.yaml: is not a YAML mapping of Recipe fields');
});

it('says so when a Recipe asked for by name does not exist', function () {
    $run = recipes()
        ->recipe('warehouses-locations-list')
        ->take(['names' => ['inbound-shipment-list'], '--stockist' => '/nowhere/stockist']);

    expect($run['exitCode'])->toBe(1)
        ->and($run['output'])->toContain('INVALID inbound-shipment-list.yaml: there is no Recipe called inbound-shipment-list');
});

it('takes Pictures at 10:30 UK time on the second Wednesday of a January', function () {
    $date = PictureTool::pictureDate()->setTimezone('Europe/London');

    expect($date->format('l H:i'))->toBe('Wednesday 10:30')
        ->and($date->month)->toBe(1)
        ->and($date->day)->toBeGreaterThan(7)->toBeLessThanOrEqual(14);
});
