<?php

namespace App\Records\Console;

use App\Records\BackupProblem;
use App\Records\RecordsBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The nightly backup (spec #30, user story 161): backs up every table of records to the
 * off-server backup store, then deletes the backups made 14 days ago or more
 * (App\Records\RecordsBackup). Scheduled daily in routes/console.php, after the night's pruning,
 * so a backup never holds a record pruned that night. Fails, saying why, when the server has no
 * backup store set up.
 */
final class BackUpRecordsCommand extends Command
{
    protected $signature = 'help:back-up-records';

    protected $description = 'Back up the records to the off-server backup store and delete backups older than 14 days';

    public function handle(RecordsBackup $backups): int
    {
        try {
            $backup = $backups->backUp();
            $deleted = $backups->deleteExpired();
        } catch (BackupProblem $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Backed up {$backup->totalRows()} ".Str::plural('row', $backup->totalRows())
            .' of records from '.count($backup->rows).' tables to '.$backup->file.' in the backup store.');
        if ($deleted !== []) {
            $this->info('Deleted the backups made '.RecordsBackup::KEEP_DAYS.' days ago or more: '.implode(', ', $deleted).'.');
        }

        return self::SUCCESS;
    }
}
