<?php

use Illuminate\Console\Command;
use Tests\Support\FixtureContent;
use Tests\Support\FixtureMainProject;
use Tests\Support\LaunchCheckRun;

/*
 * Launch Check 6, Personal details (spec #30): no email address, phone number or postcode in
 * the content outside the Trentside demo data (as the main project at the ref under test seeds
 * it) and Stockist's own contact details, so no real person's details get into help.
 */

/** The main project with a little of Trentside's demo data, as its seeders hold it. */
function aMainProjectWithTrentside(): FixtureMainProject
{
    return mainProject()
        ->file('database/seeders/Demo/Floor.php', <<<'PHP'
            <?php

            final class Floor
            {
                public function dock(): void
                {
                    $this->warehouse(
                        contact_email: 'goodsin@trentside.example',
                        contact_phone: '0115 496 0123',
                        address: ['line_1' => 'Unit 4, Colwick Quays', 'city' => 'Nottingham', 'postcode' => 'NG4 2JR'],
                    );
                }
            }
            PHP)
        ->file('database/seeders/DevDataSeeder.php', "<?php\n\n\$people = ['sarah.whitfield@trentside.example' => 'Sarah Whitfield'];\n");
}

/** Content that passes every other check, so a run's failures are this check's. */
function aCleanHelpCentre(): FixtureContent
{
    return content()->productAreas(['Inbound' => 'inbound.cluster.title'])->articleOwners(['gpl-ajackson']);
}

/** The line of a file where $text first appears, counted from 1. */
function lineIn(string $path, string $text): int
{
    $contents = (string) file_get_contents($path);

    return substr_count($contents, "\n", 0, (int) strpos($contents, $text)) + 1;
}

function personalDetailsRun(FixtureContent $content): LaunchCheckRun
{
    return launchChecks($content)->against(aMainProjectWithTrentside())->run();
}

it('passes Trentside\'s details and Stockist\'s own contact address', function () {
    $content = aCleanHelpCentre()->article('book-in-a-delivery', [], <<<'MD'
        Ask Sarah (sarah.whitfield@trentside.example) to book the slot, or ring goods in on
        0115 496 0123. Deliveries go to Trentside's dock at NG4 2JR; email [goods in](mailto:goodsin@trentside.example)
        with the ASN. Still stuck? Write to hello@firstmilemanager.co.uk.
        MD);

    $run = personalDetailsRun($content);

    expect($run->failures('personal-details'))->toBe([])
        ->and($run->exitCode)->toBe(Command::SUCCESS);
});

it('fails an email address, phone number or postcode from outside the demo data, naming its line', function () {
    $content = aCleanHelpCentre()->article('book-in-a-delivery', [], <<<'MD'
        Do the task.

        If it goes wrong, email jo.bloggs@gmail.com or ring 07911 123456.
        Our own dock is at SW1A 1AA.
        MD);

    $run = personalDetailsRun($content);

    expect($run->failures('personal-details'))->toBe([
        'articles/book-in-a-delivery.md:'.$content->lineOf('articles/book-in-a-delivery.md', 'jo.bloggs').' [personal-details.email] "jo.bloggs@gmail.com" is an email address that isn\'t in the Trentside demo data or Stockist\'s own contact details: use a Trentside one, never a real person\'s',
        'articles/book-in-a-delivery.md:'.$content->lineOf('articles/book-in-a-delivery.md', 'jo.bloggs').' [personal-details.phone] "07911 123456" is a phone number that isn\'t in the Trentside demo data or Stockist\'s own contact details: use a Trentside one, never a real person\'s',
        'articles/book-in-a-delivery.md:'.$content->lineOf('articles/book-in-a-delivery.md', 'SW1A').' [personal-details.postcode] "SW1A 1AA" is a postcode that isn\'t in the Trentside demo data or Stockist\'s own contact details: use a Trentside one, never a real person\'s',
    ])->and($run->exitCode)->toBe(Command::FAILURE);
});

it('knows a Trentside detail however it is written', function () {
    $content = aCleanHelpCentre()->article('book-in-a-delivery', [], <<<'MD'
        Ring goods in on +44 (0)115 496 0123 or 01154960123, or write to GoodsIn@Trentside.example.
        The dock's postcode is NG42JR (ng4 2jr on some labels).
        MD);

    expect(personalDetailsRun($content)->failures('personal-details'))->toBe([]);
});

it('doesn\'t take barcodes, reference numbers, dates or Location codes for personal details', function () {
    $content = aCleanHelpCentre()->article('book-in-a-delivery', [], <<<'MD'
        Scan the barcode 5012345678900 (or 012345678905 on older cartons) on delivery
        PO-2026-000417, booked for 2026-10-24 at 09:30, and put it away in A1-03-2.
        Its ASN is ASN-00042, and the order 0115496 is a short one.
        MD);

    expect(personalDetailsRun($content)->failures('personal-details'))->toBe([]);
});

it('reads every kind of content, drafts and Recipes included', function () {
    $content = aCleanHelpCentre()
        ->article('count-stock', ['status' => 'Draft'], "Ask jo@example.org to count it.\n")
        ->glossaryEntry('zone', [], "A Zone is part of a warehouse. Ring 020 7946 0991 to ask.\n")
        ->releaseNote('2026-11-14', ['summary' => 'Deliveries to M1 1AE now show their dock.'])
        ->picture('inbound-list', ['alt' => 'The Inbound list, with sam.jones@realcompany.co.uk outlined.']);

    $failures = personalDetailsRun($content)->failures('personal-details');

    expect(array_map(fn (string $line): string => explode(' ', $line)[0].' '.explode(' ', $line)[1], $failures))->toBe([
        'articles/count-stock.md:'.$content->lineOf('articles/count-stock.md', 'jo@').' [personal-details.email]',
        'glossary/zone.md:'.$content->lineOf('glossary/zone.md', '020 7946').' [personal-details.phone]',
        'release-notes/2026-11-14.md:'.$content->lineOf('release-notes/2026-11-14.md', 'M1 1AE').' [personal-details.postcode]',
        'pictures/inbound-list.yaml:'.lineIn($content->picturesPath().'/inbound-list.yaml', 'sam.jones').' [personal-details.email]',
    ]);
});
