<?php

namespace App\Site\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;

/**
 * Removes rate-limit keys whose minutes are up (spec #30, Further Note 4: a short-lived key, held
 * only for minutes). A file cache store only forgets an expired key when it is next asked for it,
 * so a reader who never comes back would otherwise leave theirs on disk; this deletes every
 * expired one. Scheduled every five minutes (routes/console.php). Stores that expire keys
 * themselves, such as Redis, need nothing.
 */
final class ForgetRateLimitsCommand extends Command
{
    protected $signature = 'help:forget-rate-limits';

    protected $description = 'Remove rate-limit keys whose minutes are up';

    public function handle(): int
    {
        $store = config()->string('cache.limiter');
        $driver = config("cache.stores.{$store}.driver");
        $path = config("cache.stores.{$store}.path");
        if ($driver !== 'file' || ! is_string($path)) {
            $this->info("The rate limiter's store ({$store}) forgets expired keys itself.");

            return self::SUCCESS;
        }

        $forgotten = 0;
        if (File::isDirectory($path)) {
            foreach (File::allFiles($path) as $file) {
                // A file store's entry starts with the time it expires, as ten digits.
                $expires = (int) substr((string) @file_get_contents($file->getPathname(), length: 10), 0, 10);
                if ($expires <= Date::now()->getTimestamp() && @unlink($file->getPathname())) {
                    $forgotten++;
                }
            }
            foreach (array_reverse(File::directories($path)) as $directory) {
                $this->removeEmpty($directory);
            }
        }

        $this->info("Forgot {$forgotten} expired rate-limit ".($forgotten === 1 ? 'key' : 'keys').'.');

        return self::SUCCESS;
    }

    private function removeEmpty(string $directory): void
    {
        foreach (File::directories($directory) as $inner) {
            $this->removeEmpty($inner);
        }
        if (File::isEmptyDirectory($directory)) {
            @rmdir($directory);
        }
    }
}
