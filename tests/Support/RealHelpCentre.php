<?php

namespace Tests\Support;

use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Assert;

/**
 * The help centre at its real address behind Cloudflare Access, for Launch Check 11's
 * --speed-at (real-help-centre/router.php): Access sends a request without the service token to
 * its sign-in page; with it, each address answers a small page. Stop it with stopAll().
 *
 *     $real = RealHelpCentre::start();
 *     launchChecks($content)->run(['--speed-at' => $real->url]);
 *     $real->tokensSeen();
 */
final class RealHelpCentre
{
    /** @var list<self> */
    private static array $started = [];

    private function __construct(
        public readonly string $url,
        private readonly string $log,
        private readonly InvokedProcess $server,
    ) {}

    public static function start(): self
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        Assert::assertNotFalse($socket, 'No free port for the real help centre.');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $log = (string) tempnam(sys_get_temp_dir(), 'help-real-address-');
        $server = Process::env(['REAL_HELP_CENTRE_LOG' => $log, 'PHP_CLI_SERVER_WORKERS' => '4'])
            ->forever()
            ->start([PHP_BINARY, '-S', $name, __DIR__.'/real-help-centre/router.php']);

        $real = new self('http://'.$name, $log, $server);
        self::$started[] = $real;
        $until = microtime(true) + 10;
        while (@fsockopen('127.0.0.1', (int) substr($name, strrpos($name, ':') + 1)) === false) {
            Assert::assertLessThan($until, microtime(true), "The real help centre didn't start: ".$server->errorOutput());
            usleep(100_000);
        }

        return $real;
    }

    /** @return list<string> Each request that carried Access's service token, as "<id> <secret> <path>". */
    public function tokensSeen(): array
    {
        return array_values(array_filter(explode("\n", (string) file_get_contents($this->log))));
    }

    public static function stopAll(): void
    {
        foreach (self::$started as $real) {
            $real->server->signal(SIGTERM);
            $real->server->wait();
            @unlink($real->log);
        }
        self::$started = [];
    }
}
