<?php

namespace App\Records\Console;

use App\Records\BackupProblem;
use App\Records\RecordsBackup;
use Illuminate\Console\Command;

/**
 * Restores the records from a backup onto a new server (spec #30, user story 162;
 * docs/rebuilding-the-server.md): the newest backup in the store, or the one named, into a
 * database that has been migrated and holds no records yet. All of it is restored or none of it.
 * The content needs no restoring: the deploy's help:rebuild makes it from the repository. It never
 * asks a question, so it works from Forge's command runner.
 */
final class RestoreRecordsCommand extends Command
{
    protected $signature = 'help:restore-records
        {backup? : The backup\'s UK day and time, such as 2026-11-04-0400 (the newest when left out)}';

    protected $description = 'Restore the records from a backup in the backup store into a fresh database';

    public function handle(RecordsBackup $backups): int
    {
        $name = $this->argument('backup');

        try {
            $backup = $backups->restore(is_string($name) && $name !== '' ? $name : null);
        } catch (BackupProblem $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Restored {$backup->file}: {$backup->totalRows()} rows of records.");
        $this->table(['Table', 'Rows'], array_map(
            fn (string $table, int $rows): array => [$table, $rows],
            array_keys($backup->rows),
            $backup->rows,
        ));

        return self::SUCCESS;
    }
}
