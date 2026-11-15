<?php

namespace App\Records\Console;

use App\Records\Records;
use Illuminate\Console\Command;

/**
 * The daily job of spec #30's "Keeping": adds up each month that is over into monthly totals,
 * kept for good, then removes Search Records, votes and Feedback Reasons older than 13 months and
 * feedback comments older than 6 (Records::prune()). Scheduled daily in routes/console.php.
 */
final class PruneRecordsCommand extends Command
{
    protected $signature = 'help:prune-records';

    protected $description = 'Remove records older than their keeping periods (13 months; comments 6)';

    public function handle(Records $records): int
    {
        $pruned = $records->prune();

        if ($pruned->monthsTotalled !== []) {
            $this->info('Added up '.implode(', ', $pruned->monthsTotalled).' into monthly totals.');
        }

        $this->info("Removed {$pruned->searchRecords} Search Records and {$pruned->votes} votes older than "
            .Records::KEEP_RECORDS_MONTHS." months, and {$pruned->comments} comments older than "
            .Records::KEEP_COMMENTS_MONTHS.' months.');

        return self::SUCCESS;
    }
}
