<?php

namespace App\IssueDesk\Console;

use App\IssueDesk\IssueDesk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Deletes "doesn't match" issues opened more than six months ago, with their comments, so the
 * readers' comments copied into them are kept no longer than the help centre keeps comments
 * (rule 11; the marketing site's privacy notice says so). The scheduler runs it every night
 * (routes/console.php). Exits 1 when GitHub couldn't be used, and logs why; what is left is
 * deleted the next night.
 */
final class DeleteOldDoesntMatchIssuesCommand extends Command
{
    protected $signature = 'help:delete-old-doesnt-match-issues';

    protected $description = 'Delete "doesn\'t match" issues, with their comments, once they are more than six months old';

    public function handle(IssueDesk $desk): int
    {
        $run = $desk->deleteOldDoesntMatchIssues();

        foreach ($run->deleted as $line) {
            $this->line($line);
        }
        if ($run->problem === null) {
            if ($run->deleted === []) {
                $this->line('No "doesn\'t match" issues are old enough to delete.');
            }

            return self::SUCCESS;
        }

        $problem = "Old \"doesn't match\" issues are left for the next run: {$run->problem}";
        $this->error($problem);
        Log::warning("Issue desk: {$problem}");

        return self::FAILURE;
    }
}
