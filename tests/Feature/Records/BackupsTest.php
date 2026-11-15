<?php

use App\Records\Records;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Daily backups (spec #30, user stories 161 and 162; ticket #60): every night, after pruning, the
 * records are backed up to the off-server store (a Cloudflare R2 bucket, faked here), and each
 * backup is deleted 14 days after it was made, so a lost server costs at most a day of records
 * and a record removed under rule 11 doesn't linger. Articles are never backed up: a new server
 * rebuilds them from the repository, and only the records are restored, into a fresh database.
 */

/** The tables a new server fills itself: Laravel's own, and those help:rebuild fills from the repository. */
const REBUILT_ON_A_NEW_SERVER = [
    'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
    'articles', 'glossary_entries', 'redirects', 'facts', 'product_areas', 'release_notes',
];

/** The backup store's settings, as the server's environment gives them, by their names there. */
const BACKUP_SETTINGS = [
    'HELP_BACKUP_ENDPOINT' => 'endpoint',
    'HELP_BACKUP_BUCKET' => 'bucket',
    'HELP_BACKUP_KEY' => 'key',
    'HELP_BACKUP_SECRET' => 'secret',
];

/** Moves the clock to a moment in the UK. */
function atUkTime(string $moment): void
{
    test()->travelTo(CarbonImmutable::parse($moment, Records::TIMEZONE));
}

/**
 * Runs the scheduled jobs due now whose command starts with one of $commands, as `schedule:run`
 * does on the server, in this process so they see the test's database, and gives back what ran.
 *
 * @return list<string>
 */
function runWhenDue(string ...$commands): array
{
    $due = collect(app(Schedule::class)->dueEvents(app()))
        ->map(fn ($event): string => Str::after((string) $event->command, "'artisan' "))
        ->filter(fn (string $command): bool => Str::startsWith($command, $commands))
        ->values()
        ->all();
    foreach ($due as $command) {
        test()->artisan($command)->assertSuccessful();
    }

    return $due;
}

/** A reader's visit: a page, a Search, a vote with its reason and comment, and "Still stuck?". */
function aReaderVisits(string $article, string $words, string $comment): void
{
    $headers = ['User-Agent' => A_READER, 'Origin' => rtrim(url('/'), '/'), 'Sec-Fetch-Site' => 'same-origin'];
    test()->withHeaders($headers)->get("/articles/{$article}")->assertOk();
    test()->withHeaders($headers)->get('/search?q='.rawurlencode($words))->assertOk();
    $response = test()->withHeaders($headers)->post("/feedback/{$article}", ['answer' => 'no'])->assertOk();
    preg_match('/name="vote" value="([^"]+)"/', (string) $response->getContent(), $vote);
    test()->withHeaders($headers)
        ->post("/feedback/{$article}/reason", ['vote' => $vote[1], 'reason' => 'It was hard to follow', 'comment' => $comment])
        ->assertOk();
    test()->withHeaders($headers)->get("/still-stuck/{$article}");
}

/**
 * Every row of every table a new server can't rebuild from the repository, table by table, in a
 * fixed order, so two databases' records can be compared.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function recordsAsTheyAre(): array
{
    return collect(Schema::getTableListing(schemaQualified: false))
        ->reject(fn (string $table): bool => in_array($table, REBUILT_ON_A_NEW_SERVER, true))
        ->sort()
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->get()
            ->map(fn (object $row): array => (array) $row)
            ->sortBy(fn (array $row): string => (string) json_encode($row))
            ->values()
            ->all()])
        ->all();
}

/** Empties every record table, as on a new server whose database has just been migrated. */
function aFreshDatabase(): void
{
    foreach (array_keys(recordsAsTheyAre()) as $table) {
        DB::table($table)->delete();
    }
}

/** @return list<string> the backups in the store, oldest first */
function backupsInTheStore(): array
{
    $files = Storage::disk('backups')->allFiles();
    sort($files);

    return $files;
}

/** @return list<string> a backup's lines, unzipped */
function backupLines(string $file): array
{
    return explode("\n", trim((string) gzdecode((string) Storage::disk('backups')->get($file))));
}

beforeEach(function () {
    config(['help.going_public' => true]);
    foreach (BACKUP_SETTINGS as $setting) {
        config(["filesystems.disks.backups.{$setting}" => "test-{$setting}"]);
    }
    Storage::fake('backups');
    atUkTime('2026-11-03 10:00');

    content()
        ->article('book-in-a-pallet', ['title' => 'Book in a pallet', 'checked_on' => '2026-10-24'], "Wave the scanner over the goods-in label.\n")
        ->article('count-stock', ['title' => 'Count stock', 'checked_on' => '2026-10-24'])
        ->publish();
});

it('backs up the records every night after pruning, and a restore into a fresh database gives the same records', function () {
    aReaderVisits('book-in-a-pallet', 'pallet label 07700 900123', 'The label step is missing.');
    atUkTime('2026-11-20 15:00');
    aReaderVisits('count-stock', 'cycle count', 'Which count do I start?');
    app(Records::class)->recordUptime(CarbonImmutable::parse('2026-11-01'), 99.95, CarbonImmutable::parse('2026-11-30'));

    atUkTime('2026-12-01 03:30');
    expect(runWhenDue('help:prune-records', 'help:back-up-records'))->toBe(['help:prune-records']);
    atUkTime('2026-12-01 04:00');
    expect(runWhenDue('help:prune-records', 'help:back-up-records'))->toBe(['help:back-up-records']);

    $records = recordsAsTheyAre();
    expect(backupsInTheStore())->toBe(['records/2026-12-01-0400.jsonl.gz'])
        ->and(array_filter($records, fn (array $rows): bool => $rows === []))->toBe([]);

    aFreshDatabase();
    $this->artisan('help:restore-records')
        ->expectsOutputToContain('Restored records/2026-12-01-0400.jsonl.gz')
        ->assertSuccessful();

    $november = CarbonImmutable::parse('2026-11-01');
    expect(recordsAsTheyAre())->toBe($records)
        ->and(app(Records::class)->monthlyTotals($november)?->votes())->toBe(2)
        ->and(app(Records::class)->stillStuckCounts($november, $november->endOfMonth()))->toBe(['book-in-a-pallet' => 1, 'count-stock' => 1])
        ->and(app(Records::class)->monthlyUptime($november)?->uptime)->toBe(99.95);
});

it('backs up every table but those a new server rebuilds, and never an Article', function () {
    aReaderVisits('book-in-a-pallet', 'pallet label', 'The label step is missing.');
    atUkTime('2026-11-04 04:00');
    runWhenDue('help:back-up-records');

    $lines = backupLines('records/2026-11-04-0400.jsonl.gz');
    $tables = array_keys(json_decode($lines[array_key_last($lines)], true)['tables']);
    sort($tables);

    expect($tables)->toBe(array_keys(recordsAsTheyAre()))
        ->and($tables)->not->toContain('articles')
        ->and(implode("\n", $lines))->toContain('The label step is missing.')
        ->and(implode("\n", $lines))->not->toContain('Book in a pallet')
        ->and(implode("\n", $lines))->not->toContain('Wave the scanner over the goods-in label.');
});

it('deletes each backup 14 days after it was made, leaving anything else in the store alone', function () {
    Storage::disk('backups')->put('readme.txt', 'Backups of the help centre\'s records.');
    foreach (range(1, 16) as $day) {
        atUkTime(sprintf('2026-11-%02d 04:00', $day));
        runWhenDue('help:back-up-records');
    }

    expect(backupsInTheStore())->toBe([
        'readme.txt',
        ...array_map(fn (int $day): string => sprintf('records/2026-11-%02d-0400.jsonl.gz', $day), range(3, 16)),
    ]);
});

it('restores the backup named, and says which backups there are when it can\'t find one', function () {
    aReaderVisits('book-in-a-pallet', 'pallet label', 'First night.');
    atUkTime('2026-11-04 04:00');
    $this->artisan('help:back-up-records')->assertSuccessful();
    $firstNight = recordsAsTheyAre();

    atUkTime('2026-11-04 10:00');
    aReaderVisits('count-stock', 'cycle count', 'Second night.');
    atUkTime('2026-11-05 04:00');
    $this->artisan('help:back-up-records')->assertSuccessful();

    aFreshDatabase();
    $this->artisan('help:restore-records', ['backup' => '2026-11-01-0400'])
        ->expectsOutputToContain('There is no backup 2026-11-01-0400. The store holds records/2026-11-04-0400.jsonl.gz, records/2026-11-05-0400.jsonl.gz.')
        ->assertFailed();
    $this->artisan('help:restore-records', ['backup' => '2026-11-04-0400'])->assertSuccessful();

    expect(recordsAsTheyAre())->toBe($firstNight);
});

it('restores only into a database that holds no records yet', function () {
    aReaderVisits('book-in-a-pallet', 'pallet label', 'Kept once.');
    atUkTime('2026-11-04 04:00');
    $this->artisan('help:back-up-records')->assertSuccessful();
    $records = recordsAsTheyAre();

    $this->artisan('help:restore-records')
        ->expectsOutputToContain('already holds records')
        ->assertFailed();

    expect(recordsAsTheyAre())->toBe($records);
});

it('restores nothing from a damaged backup', function () {
    aReaderVisits('book-in-a-pallet', 'pallet label', 'Cut short.');
    atUkTime('2026-11-04 04:00');
    $this->artisan('help:back-up-records')->assertSuccessful();
    $lines = backupLines('records/2026-11-04-0400.jsonl.gz');
    Storage::disk('backups')->put('records/2026-11-04-0400.jsonl.gz', (string) gzencode(implode("\n", array_slice($lines, 0, -1))."\n"));

    aFreshDatabase();
    $this->artisan('help:restore-records')
        ->expectsOutputToContain('is damaged')
        ->assertFailed();

    expect(array_filter(recordsAsTheyAre()))->toBe([]);
});

it('fails, naming the setting, when the server has no backup store set up', function (string $name) {
    config(['filesystems.disks.backups.'.BACKUP_SETTINGS[$name] => null]);

    expect(Artisan::call('help:back-up-records'))->toBe(1)
        ->and(Artisan::output())->toContain("{$name} is not set")
        ->and(Artisan::call('help:restore-records'))->toBe(1)
        ->and(Artisan::output())->toContain("{$name} is not set")
        ->and(backupsInTheStore())->toBe([]);
})->with(array_keys(BACKUP_SETTINGS));
