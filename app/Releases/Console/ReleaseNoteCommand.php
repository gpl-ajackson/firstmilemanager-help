<?php

namespace App\Releases\Console;

use App\Content\InvalidContent;
use App\Releases\ReleaseNoteExists;
use App\Releases\Releases;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Assembles a FMM Release's Release Note (plan, S7 step 8): gathers the Change Summaries
 * in the Next Release folder into content/release-notes/<yyyy-mm-dd>.md, grouped by Product
 * Area with "Affects your clients" marked, and removes them, so the change that publishes the
 * Release Note also empties the folder. With nothing waiting, it says so and writes nothing.
 */
final class ReleaseNoteCommand extends Command
{
    protected $signature = 'help:release-note
        {--date= : The FMM Release\'s date, YYYY-MM-DD (default: today in the UK)}
        {--content= : The content folder (default: config help.content_path)}';

    protected $description = 'Write the Release Note from the Next Release folder\'s Change Summaries, and empty the folder';

    public function handle(Releases $releases): int
    {
        $option = $this->option('content');
        $path = is_string($option) && $option !== '' ? $option : Config::string('help.content_path');

        $date = $this->releaseDate();
        if ($date === null) {
            return self::FAILURE;
        }

        try {
            $draft = $releases->publishReleaseNote($path, $date);
        } catch (InvalidContent $invalid) {
            $this->error('The Next Release folder has Change Summaries the rebuild would refuse. Nothing was written:');
            foreach ($invalid->problems as $problem) {
                $this->line("  {$problem}");
            }

            return self::FAILURE;
        } catch (ReleaseNoteExists $exists) {
            $this->error($exists->getMessage().'. Nothing was written; give the release\'s date with --date.');

            return self::FAILURE;
        }

        if ($draft === null) {
            $this->info('There is nothing to release: the Next Release folder has no Change Summaries. Nothing was written.');

            return self::SUCCESS;
        }

        $count = count($draft->changeSummaries);
        $this->info("Wrote {$draft->file} from {$count} Change ".Str::plural('Summary', $count)
            .' ('.implode(', ', $draft->productAreas()).'), and removed '.($count === 1 ? 'it' : 'them').' from the Next Release folder:');
        foreach ($draft->changeSummaries as $source) {
            $this->line("  {$source->file}");
        }
        $this->line('Reword its summary if you like, then commit the Release Note and the removals in one change.');

        return self::SUCCESS;
    }

    private function releaseDate(): ?CarbonImmutable
    {
        $option = $this->option('date');
        $date = Releases::releaseDate(is_string($option) ? $option : null);
        if ($date === null) {
            $this->error('"'.(is_string($option) ? $option : '').'" is not a date written YYYY-MM-DD. Nothing was written.');
        }

        return $date;
    }
}
