<?php

namespace App\Content\Console;

use App\Content\ContentLibrary;
use App\Content\InvalidContent;
use App\Content\RebuildSummary;
use App\Search\SearchService;
use App\Search\SearchUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The content rebuild a deploy runs after migrating: the database copy of every Article,
 * rendered once, from the content folder, and the search index of what it publishes, in one
 * go: if either fails, neither changes. Later tickets add synonyms and the rest of the content
 * here, so a deploy keeps running this one command.
 */
final class RebuildCommand extends Command
{
    protected $signature = 'help:rebuild
        {--content= : The content folder to read (default: config help.content_path)}
        {--pictures= : The pictures folder to read (default: config help.pictures_path)}';

    protected $description = 'Rebuild the help centre from its content folder, as a deploy does';

    public function handle(ContentLibrary $library, SearchService $search): int
    {
        $option = $this->option('content');
        $path = is_string($option) && $option !== '' ? $option : Config::string('help.content_path');

        try {
            // One transaction around both: the search index is swapped in last, and if indexing
            // fails the database copy rolls back, so readers keep the old content everywhere.
            $pictures = $this->option('pictures');
            $picturesPath = is_string($pictures) && $pictures !== '' ? $pictures : null;
            $summary = DB::transaction(function () use ($library, $search, $path, $picturesPath): RebuildSummary {
                $summary = $library->rebuild($path, $picturesPath);
                $search->rebuildIndex();

                return $summary;
            });
        } catch (InvalidContent $invalid) {
            $this->error("The content folder {$path} can't be published, so nothing was changed:");
            foreach ($invalid->problems as $problem) {
                $this->line("  {$problem}");
            }

            return self::FAILURE;
        } catch (SearchUnavailable $unavailable) {
            $this->error("The search index couldn't be rebuilt, so nothing was changed: {$unavailable->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Rebuilt {$summary->articles()} ".Str::plural('Article', $summary->articles())." from {$path}: "
            ."{$summary->published} published, ".($summary->waitingForDemo > 0 ? "{$summary->waitingForDemo} waiting for the demo, " : '')
            ."{$summary->drafts} draft, {$summary->retired} retired.");
        $entries = $summary->glossaryEntries + $summary->clientGlossaryEntries;
        $this->info("Rebuilt {$entries} Glossary ".Str::plural('Entry', $entries).': '
            ."{$summary->glossaryEntries} in the Glossary, {$summary->clientGlossaryEntries} in the Client Glossary.");
        $this->info("Rebuilt {$summary->releaseNotes} Release ".Str::plural('Note', $summary->releaseNotes).'; '
            .$summary->changeSummaries.' Change '.Str::plural('Summary', $summary->changeSummaries).' '
            .($summary->changeSummaries === 1 ? 'waits' : 'wait').' for the next FMM Release.');

        // Listed, never refused: readers see these links' words as plain text, and the Links
        // check (Launch Check 3) fails them on the pull request.
        $broken = count($summary->brokenLinks);
        if ($broken > 0) {
            $this->warn($broken.' '.Str::plural('link', $broken).' '.($broken === 1 ? 'leads' : 'lead')
                .' nowhere, so readers see '.($broken === 1 ? 'its' : 'their').' words as plain text:');
            foreach ($summary->brokenLinks as $link) {
                $this->line("  {$link}");
            }
        }

        return self::SUCCESS;
    }
}
