<?php

namespace App\Records;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use JsonException;

/**
 * Daily backups of the records (spec #30, user stories 161 and 162; plan, "Keeping it running"):
 * every table Records keeps, copied to the off-server backup store (the `backups` disk, a
 * Cloudflare R2 bucket) once a night and deleted 14 days after it was made, so a lost server
 * costs at most a day of records and a record removed under rule 11 doesn't linger. Articles,
 * Glossary Entries and the rest of the content are never backed up: a new server rebuilds them
 * from the repository (help:rebuild), and only the records are restored into its fresh database.
 *
 * A backup is one gzipped file of JSON lines, records/<UK day>-<hhmm>.jsonl.gz: a line for each
 * row ({"table": ..., "row": {...}}), then a last line listing every table with its rows, so a
 * restore can tell a whole backup from a damaged one. One file is written in one go, so the store
 * never holds half a backup.
 */
final class RecordsBackup
{
    public const DISK = 'backups';

    /** Each backup is deleted once it is this many days old (the bucket's own rule deletes it too). */
    public const KEEP_DAYS = 14;

    /**
     * Every table of records: what Records keeps and a new server can't rebuild from the
     * repository. A new table of records belongs here, or it isn't backed up.
     */
    public const TABLES = [
        'search_records',
        'article_feedback',
        'page_view_counts',
        'still_stuck_counts',
        'totalled_months',
        'monthly_search_totals',
        'monthly_vote_totals',
        'monthly_uptime',
    ];

    private const FOLDER = 'records';

    /** A backup's name: the UK day and time it was made. */
    private const NAME = 'Y-m-d-Hi';

    private const NAME_PATTERN = '/\A'.self::FOLDER.'\/(\d{4}-\d{2}-\d{2}-\d{4})\.jsonl\.gz\z/';

    /** Rows restored in one insert. */
    private const CHUNK = 500;

    /** The server's settings for the backup store, by their names in its environment. */
    private const SETTINGS = [
        'endpoint' => 'HELP_BACKUP_ENDPOINT',
        'bucket' => 'HELP_BACKUP_BUCKET',
        'key' => 'HELP_BACKUP_KEY',
        'secret' => 'HELP_BACKUP_SECRET',
    ];

    /**
     * Backs up every table of records to the store, as it is now.
     *
     * @throws BackupProblem when the store isn't set up
     */
    public function backUp(): Backup
    {
        $store = $this->store();
        $file = self::FOLDER.'/'.CarbonImmutable::now(Records::TIMEZONE)->format(self::NAME).'.jsonl.gz';

        $local = (string) tempnam(sys_get_temp_dir(), 'help-records-');
        try {
            $gz = gzopen($local, 'wb9');
            if ($gz === false) {
                throw new BackupProblem("A backup can't be written to {$local}.");
            }
            $rows = [];
            foreach (self::TABLES as $table) {
                $count = 0;
                foreach (DB::table($table)->cursor() as $row) {
                    gzwrite($gz, self::line(['table' => $table, 'row' => $row]));
                    $count++;
                }
                $rows[$table] = $count;
            }
            gzwrite($gz, self::line(['tables' => $rows]));
            gzclose($gz);

            $stream = fopen($local, 'rb');
            if ($stream === false) {
                throw new BackupProblem("The backup written to {$local} can't be read.");
            }
            $store->writeStream($file, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        } finally {
            @unlink($local);
        }

        return new Backup($file, $rows);
    }

    /**
     * Deletes every backup made KEEP_DAYS days ago or more, and gives back those deleted, oldest
     * first. Anything else in the store is left alone.
     *
     * @return list<string>
     *
     * @throws BackupProblem when the store isn't set up
     */
    public function deleteExpired(): array
    {
        $store = $this->store();
        $deleteFrom = CarbonImmutable::now(Records::TIMEZONE)->subDays(self::KEEP_DAYS);

        $expired = array_keys(array_filter(
            $this->backups($store),
            fn (CarbonImmutable $made): bool => $made->lessThanOrEqualTo($deleteFrom),
        ));
        if ($expired !== []) {
            $store->delete($expired);
        }

        return $expired;
    }

    /**
     * Restores a backup, the newest when none is named, into a database that holds no records
     * yet (a new server's, straight after its migrations), all of it or nothing. A backup is
     * named by its UK day and time (2026-11-04-0400), or its file.
     *
     * @throws BackupProblem when the store isn't set up, there is no such backup, it is damaged,
     *                       or the database already holds records
     */
    public function restore(?string $name = null): Backup
    {
        $store = $this->store();
        $backups = $this->backups($store);
        if ($backups === []) {
            throw new BackupProblem('The backup store holds no backups of the records.');
        }
        $file = $name === null ? array_key_last($backups) : self::FOLDER.'/'.basename(basename($name, '.gz'), '.jsonl').'.jsonl.gz';
        if (! isset($backups[$file])) {
            throw new BackupProblem("There is no backup {$name}. The store holds ".implode(', ', array_keys($backups)).'.');
        }

        $local = (string) tempnam(sys_get_temp_dir(), 'help-records-');
        try {
            $download = $store->readStream($file);
            $copy = fopen($local, 'wb');
            if ($download === null || $copy === false) {
                throw new BackupProblem("{$file} can't be read from the backup store.");
            }
            stream_copy_to_stream($download, $copy);
            fclose($copy);

            return DB::transaction(fn (): Backup => $this->restoreFrom($local, $file));
        } finally {
            @unlink($local);
        }
    }

    private function restoreFrom(string $local, string $file): Backup
    {
        $holding = array_values(array_filter(self::TABLES, fn (string $table): bool => DB::table($table)->exists()));
        if ($holding !== []) {
            throw new BackupProblem('This database already holds records ('.implode(', ', $holding).'), so nothing was restored. '
                .'Restore only into a new server\'s fresh database, straight after its migrations.');
        }

        $gz = gzopen($local, 'rb');
        if ($gz === false) {
            throw new BackupProblem("{$file} can't be opened.");
        }
        $damaged = fn (string $why): BackupProblem => new BackupProblem("{$file} is damaged ({$why}), so nothing was restored. Restore an earlier backup.");

        $rows = array_fill_keys(self::TABLES, 0);
        $pending = [];
        $listed = null;
        try {
            while (($line = gzgets($gz)) !== false) {
                if ($listed !== null) {
                    throw $damaged('a row after its list of tables');
                }
                try {
                    $entry = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw $damaged('a line that isn\'t JSON');
                }
                if (is_array($entry) && is_array($entry['tables'] ?? null)) {
                    $listed = $entry['tables'];

                    continue;
                }
                $table = is_array($entry) && is_string($entry['table'] ?? null) ? $entry['table'] : null;
                if ($table === null || ! in_array($table, self::TABLES, true) || ! is_array($entry['row'] ?? null)) {
                    throw $damaged('a line that isn\'t a row of records');
                }
                $pending[$table][] = $entry['row'];
                $rows[$table]++;
                if (count($pending[$table]) >= self::CHUNK) {
                    DB::table($table)->insert($pending[$table]);
                    $pending[$table] = [];
                }
            }
        } finally {
            gzclose($gz);
        }
        if ($listed === null) {
            throw $damaged('it doesn\'t end with its list of tables');
        }
        if (array_diff_key(array_filter($rows), $listed) !== []) {
            throw $damaged('it has rows of a table its list leaves out');
        }
        foreach ($listed as $table => $count) {
            if (! in_array($table, self::TABLES, true) || $count !== $rows[$table]) {
                throw $damaged("its list of tables doesn't match its rows of {$table}");
            }
        }
        foreach ($pending as $table => $chunk) {
            if ($chunk !== []) {
                DB::table($table)->insert($chunk);
            }
        }

        return new Backup($file, array_intersect_key($rows, $listed));
    }

    /**
     * The backups in the store, oldest first, with when each was made.
     *
     * @return array<string, CarbonImmutable> file => made
     */
    private function backups(Filesystem $store): array
    {
        $backups = [];
        foreach ($store->files(self::FOLDER) as $file) {
            if (preg_match(self::NAME_PATTERN, $file, $name) === 1) {
                $made = CarbonImmutable::createFromFormat('!'.self::NAME, $name[1], Records::TIMEZONE);
                if ($made !== null) {
                    $backups[$file] = $made;
                }
            }
        }
        ksort($backups);

        return $backups;
    }

    /**
     * The backup store, once the server has every setting it needs.
     *
     * @throws BackupProblem
     */
    private function store(): Filesystem
    {
        $missing = array_values(array_filter(
            self::SETTINGS,
            fn (string $name, string $setting): bool => ! is_string($value = Config::get('filesystems.disks.'.self::DISK.'.'.$setting)) || $value === '',
            ARRAY_FILTER_USE_BOTH,
        ));
        if ($missing !== []) {
            throw new BackupProblem(implode(', ', $missing).(count($missing) === 1 ? ' is' : ' are')
                .' not set on this server, so there is no backup store.');
        }

        return Storage::disk(self::DISK);
    }

    /** @param  array<string, mixed>  $entry */
    private static function line(array $entry): string
    {
        return json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }
}
