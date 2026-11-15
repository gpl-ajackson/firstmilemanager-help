<?php

use App\Pictures\PictureTool;
use App\Pictures\StockistCopy;
use App\Pictures\StockistUnavailable;
use Illuminate\Support\Facades\Http;

/*
 * Stockist's server for one take, against a real Stockist like the rest of this suite: a busy
 * CI server can stop it part way, and the take is then had again on a fresh copy.
 */

function stockistForPictures(): StockistCopy
{
    return app(PictureTool::class)->stockist(config('help.pictures.work_path'), null, null, function (string $line): void {});
}

/** Stops the server answering at $url as a machine out of memory would (SIGKILL). */
function killServerAt(string $url): void
{
    // Found by its command line in /proc (pgrep isn't on every runner).
    $listening = '127.0.0.1:'.parse_url($url, PHP_URL_PORT);
    $pids = [];
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmdline) {
        $arguments = explode("\0", (string) @file_get_contents($cmdline));
        if (in_array('-S', $arguments, true) && in_array($listening, $arguments, true)) {
            $pids[] = (int) basename(dirname($cmdline));
        }
    }
    expect($pids)->not->toBeEmpty("no server found at {$url}");
    foreach ($pids as $pid) {
        posix_kill($pid, SIGKILL);
    }
    usleep(200_000);
}

it('has the take again on a fresh copy when the server stops part way', function () {
    $stockist = stockistForPictures();
    $urls = [];

    $result = $stockist->withFreshCopy(function (string $url) use (&$urls): string {
        $urls[] = $url;
        expect(Http::get("{$url}/login")->successful())->toBeTrue();
        if (count($urls) === 1) {
            killServerAt($url);

            return 'seen with the server gone';
        }

        return 'seen with the server up';
    });

    expect($result)->toBe('seen with the server up')
        ->and($urls)->toHaveCount(2);
});

it('says why when the server stops on the second go too', function () {
    $stockist = stockistForPictures();
    $goes = 0;

    expect(function () use ($stockist, &$goes): void {
        $stockist->withFreshCopy(function (string $url) use (&$goes): void {
            $goes++;
            killServerAt($url);
            throw new RuntimeException('the camera lost the page');
        });
    })->toThrow(StockistUnavailable::class, 'exit code 137, killed');

    expect($goes)->toBe(2);
});

it('passes on what went wrong in a take while the server is up', function () {
    $stockist = stockistForPictures();
    $goes = 0;

    expect(function () use ($stockist, &$goes): void {
        $stockist->withFreshCopy(function () use (&$goes): void {
            $goes++;
            throw new RuntimeException('the camera failed');
        });
    })->toThrow(RuntimeException::class, 'the camera failed');

    expect($goes)->toBe(1);
});
