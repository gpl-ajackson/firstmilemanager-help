<?php

namespace App\Preview\Console;

use App\Content\InvalidContent;
use App\Preview\HelpPreview;
use App\Preview\UnusableFolder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Writes a pull request's Help Preview (#59): a static copy of its pages for Cloudflare Pages,
 * drafts marked, search and feedback inert, never indexed and never recorded. The Help Preview
 * workflow runs it, then deploys the folder with wrangler. It changes nothing in the help
 * centre's database or search index, and needs the migrated database but not Meilisearch.
 */
final class PreviewCommand extends Command
{
    protected $signature = 'help:preview
        {--into= : The new or empty folder to write the preview into}
        {--content= : The content folder to read (default: config help.content_path)}
        {--pictures= : The pictures folder to read (default: config help.pictures_path)}
        {--pull-request= : The pull request the preview is of, named on every page}';

    protected $description = 'Write a static Help Preview of the content folder, for Cloudflare Pages';

    public function handle(HelpPreview $preview): int
    {
        $into = $this->option('into');
        if (! is_string($into) || $into === '') {
            $this->error('Give the folder to write the Help Preview into: --into=<folder>');

            return self::INVALID;
        }
        $pullRequest = $this->option('pull-request');
        if ($pullRequest !== null && preg_match('/^[1-9][0-9]*$/', $pullRequest) !== 1) {
            $this->error('--pull-request must be a pull request number, such as 42');

            return self::INVALID;
        }
        $content = $this->option('content');
        $path = is_string($content) && $content !== '' ? $content : Config::string('help.content_path');
        $pictures = $this->option('pictures');

        try {
            $written = $preview->export(
                $path,
                is_string($pictures) && $pictures !== '' ? $pictures : null,
                rtrim($into, '/'),
                $pullRequest === null ? null : (int) $pullRequest,
            );
        } catch (InvalidContent $invalid) {
            $this->error("The content folder {$path} can't be previewed, so nothing was written:");
            foreach ($invalid->problems as $problem) {
                $this->line("  {$problem}");
            }

            return self::FAILURE;
        } catch (UnusableFolder $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->info("Wrote the Help Preview of {$path} into {$into}: {$written->pages} "
            .Str::plural('page', $written->pages).", {$written->drafts} Draft "
            .Str::plural('Article', $written->drafts).' marked as '.Str::plural('draft', $written->drafts)
            .", {$written->files} files in all.");

        return self::SUCCESS;
    }
}
