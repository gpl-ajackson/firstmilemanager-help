<?php

namespace App\IssueDesk\Console;

use App\IssueDesk\IssueDesk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends the "doesn't match" votes waiting to their Article Owners as issues on this repository
 * (spec #30, user stories 70 to 72). The scheduler runs it every five minutes (routes/console.php),
 * so a vote reaches its owner the same day; a vote GitHub didn't take waits for the next run.
 * Exits 1 when any vote is left waiting, and logs why.
 */
final class DoesntMatchIssuesCommand extends Command
{
    protected $signature = 'help:doesnt-match-issues';

    protected $description = 'Send "doesn\'t match" votes to their Article Owners as issues on this repository';

    public function handle(IssueDesk $desk): int
    {
        $run = $desk->sendDoesntMatchVotes();

        foreach ($run->sent as $line) {
            $this->line($line);
        }
        if ($run->sent === [] && $run->waiting === 0) {
            $this->line('No "doesn\'t match" votes to send.');
        }
        if ($run->waiting === 0) {
            return self::SUCCESS;
        }

        $waiting = $run->waiting.' "doesn\'t match" '.Str::plural('vote', $run->waiting).' '.($run->waiting === 1 ? 'waits' : 'wait')
            .' for the next run: '.$run->problem;
        $this->error($waiting);
        Log::warning("Issue desk: {$waiting}");

        return self::FAILURE;
    }
}
