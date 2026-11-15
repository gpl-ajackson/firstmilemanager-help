<?php

namespace App\Site\Console;

use App\Site\Asset;
use Illuminate\Console\Command;

/**
 * Works out the fingerprint of every style, script, font and image in public/ once, so pages
 * link them without reading and hashing them on every request (App\Site\Asset::cache()). Run by
 * `php artisan optimize` on each deploy (AppServiceProvider); `optimize:clear` or
 * help:clear-assets removes it, and pages work the fingerprints out as they go again.
 */
final class CacheAssetsCommand extends Command
{
    protected $signature = 'help:cache-assets';

    protected $description = 'Work out the fingerprints of the files in public/ once, for every page to use';

    public function handle(): int
    {
        $this->info('Worked out the fingerprints of '.Asset::cache().' files in public/.');

        return self::SUCCESS;
    }
}
