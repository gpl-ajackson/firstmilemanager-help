<?php

namespace App\LaunchChecks\Console;

use App\LaunchChecks\HelpOnly\PullRequestDescription;
use Illuminate\Console\Command;

/**
 * Prints the main project's ref a support-site pull request is checked against: its paired
 * change (refs/pull/<n>/head) when the description links a main-project pull request, or main.
 * CI checks that ref out before running help:launch-checks.
 */
final class PairedChangeCommand extends Command
{
    protected $signature = 'help:paired-change
        {--pull-request-description= : A file holding the pull request\'s description (none: main)}';

    protected $description = 'Print the main-project ref a support-site pull request is checked against';

    public function handle(): int
    {
        $file = $this->option('pull-request-description');
        $description = is_string($file) && $file !== '' && is_file($file) ? (string) file_get_contents($file) : '';

        $this->line((new PullRequestDescription($description))->mainProjectRef());

        return self::SUCCESS;
    }
}
