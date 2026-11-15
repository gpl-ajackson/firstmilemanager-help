<?php

namespace App\Site\Console;

use App\Site\Asset;
use Illuminate\Console\Command;

/** Removes the fingerprints help:cache-assets worked out, so pages work them out as they go. */
final class ClearAssetsCommand extends Command
{
    protected $signature = 'help:clear-assets';

    protected $description = 'Remove the fingerprints help:cache-assets worked out';

    public function handle(): int
    {
        Asset::forgetCache();
        $this->info('Removed the cached fingerprints of the files in public/.');

        return self::SUCCESS;
    }
}
