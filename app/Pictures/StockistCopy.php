<?php

namespace App\Pictures;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use PDO;
use Throwable;

/**
 * Stockist at one commit, installed, with the Trentside demo seeded once at the Picture Date
 * and kept as a Postgres template database (ADR-0005, stockist#277's CLOCK_HELD_AT). Each
 * Recipe gets a fresh copy of that database and its own Stockist server with the clock held
 * at the same moment, so the same screen always gives the same Picture.
 *
 * Kept between runs, under the work folder: the fetched source (source/), one install per
 * commit (stockist-<commit>/), and the seeded template (<prefix>_<commit>_<moment>), so a
 * second run at the same commit starts at once.
 */
final class StockistCopy
{
    /** Stockist's own app key while it runs for pictures: local, never a secret. */
    private const string APP_KEY = 'base64:cGljdHVyZXMtcGljdHVyZXMtcGljdHVyZXMtcGljdHU=';

    /** Everyone in the Trentside demo signs in with this password (DevDataSeeder). */
    public const string PASSWORD = 'password';

    /**
     * @param  array{host: string, port: int, username: string, password: string}  $postgres
     */
    private function __construct(
        public readonly string $commit,
        public readonly string $path,
        public readonly CarbonImmutable $moment,
        private readonly string $template,
        private readonly array $postgres,
    ) {}

    /**
     * Fetches $ref from $repository, installs it and seeds Trentside at $moment, reusing
     * whatever an earlier run left ready.
     *
     * @param  array{host: string, port: int, username: string, password: string}  $postgres
     * @param  Closure(string): void  $say  progress, one line at a time
     *
     * @throws StockistUnavailable
     */
    public static function prepare(
        string $repository,
        string $ref,
        CarbonImmutable $moment,
        string $workPath,
        string $databasePrefix,
        array $postgres,
        bool $offlineFonts,
        Closure $say,
    ): self {
        File::ensureDirectoryExists($workPath);
        $commit = self::fetch($repository, $ref, $workPath);
        $short = substr($commit, 0, 12);
        $say("Stockist {$ref} is commit {$short}.");

        $path = "{$workPath}/stockist-{$short}";
        if (! is_file("{$path}/.pictures-installed")) {
            $say("Installing Stockist {$short} (Composer, npm and its asset build); a few minutes the first time.");
            self::install($workPath, $commit, $path, $offlineFonts);
        }

        $stockistConfig = (string) @file_get_contents("{$path}/config/stockist.php");
        if (! str_contains($stockistConfig, 'CLOCK_HELD_AT')) {
            throw new StockistUnavailable("Stockist {$ref} ({$short}) can't hold its clock at a moment (CLOCK_HELD_AT, stockist#277), so its screens would change with every take. Take Pictures from a version that has it.");
        }

        $template = strtolower("{$databasePrefix}_{$short}_".$moment->utc()->format('YmdHi'));
        $copy = new self($commit, $path, $moment, $template, $postgres);

        if (! $copy->databaseExists($template)) {
            $say('Seeding the Trentside demo at the Picture Date ('.$moment->format('j F Y H:i T').'); about two minutes, once per commit.');
            $copy->seed();
        }

        return $copy;
    }

    /**
     * Starts Stockist on a fresh copy of the seeded demo, runs $use with its address, then
     * stops it and drops the copy.
     *
     * A busy machine can stop Stockist's server part way (a shared CI server running out of
     * memory, or another program taking its port first). What $use saw then says nothing about
     * the Recipe, so it is thrown away and $use runs once more on another fresh copy; a second
     * stop is reported, with the server's last words, as Stockist unavailable.
     *
     * @template T
     *
     * @param  Closure(string, PDO): T  $use  given the base URL and a connection to the copy
     * @return T
     *
     * @throws StockistUnavailable
     */
    public function withFreshCopy(Closure $use): mixed
    {
        $stopped = '';
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            [$done, $result, $stopped] = $this->onFreshCopy($use);
            if ($done) {
                return $result;
            }
        }

        throw new StockistUnavailable($stopped);
    }

    /**
     * One go at withFreshCopy: whether $use ran with the server up to the end, its result, and
     * otherwise why the server stopped.
     *
     * @template T
     *
     * @param  Closure(string, PDO): T  $use
     * @return array{0: true, 1: T, 2: null}|array{0: false, 1: null, 2: string}
     */
    private function onFreshCopy(Closure $use): array
    {
        $database = "{$this->template}_take";
        $this->dropDatabase($database);
        $this->admin()->exec('CREATE DATABASE '.$this->quote($database).' TEMPLATE '.$this->quote($this->template));

        $port = self::freePort();
        $url = "http://127.0.0.1:{$port}";
        // The server notes every request; written to a file, so a long take never fills the
        // pipe nobody reads while the camera works, which would leave the server waiting.
        $log = "{$this->path}/storage/logs/pictures-server.log";
        File::ensureDirectoryExists(dirname($log));
        File::put($log, '');
        // OPcache serves the built-in server (it isn't the cli SAPI), but its JIT stays off: on the
        // ARM CI server (setup-php turns the JIT on) it crashed the server part way through a take.
        $server = Process::path("{$this->path}/public")
            ->env([...$this->environment($database, $url), 'PHP_CLI_SERVER_WORKERS' => '4'])
            ->forever()
            ->start(['sh', '-c', 'exec "$@" >> "$0" 2>&1', $log, PHP_BINARY, '-d', 'opcache.jit=disable', '-S', "127.0.0.1:{$port}", "{$this->path}/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"]);

        try {
            try {
                $this->waitUntilServing($server, $url, $log);
                $result = $use($url, $this->connect($database));
            } catch (Throwable $failure) {
                if ($server->running()) {
                    throw $failure;
                }

                return [false, null, $this->whyStopped($server, $log)];
            }

            return $server->running() ? [true, $result, null] : [false, null, $this->whyStopped($server, $log)];
        } finally {
            // SIGTERM, then SIGKILL if it hasn't stopped in 10 seconds; nothing if it already has.
            $server->stop(10);
            $this->dropDatabase($database);
        }
    }

    private function whyStopped(InvokedProcess $server, string $log): string
    {
        $exitCode = $server->stop(0);
        $lines = array_slice(array_filter(explode("\n", (string) @file_get_contents($log)), fn (string $line): bool => trim($line) !== ''), -10);

        return "Stockist's server stopped while a Picture was being taken, twice (exit code ".($exitCode ?? 'unknown').
            match ($exitCode) {
                137 => ', killed: usually the machine ran out of memory',
                139 => ', a segmentation fault: PHP itself crashed',
                default => '',
            }.'). Its last words:'."\n".implode("\n", $lines);
    }

    private static function fetch(string $repository, string $ref, string $workPath): string
    {
        $source = "{$workPath}/source";
        if (! is_dir("{$source}/.git")) {
            File::ensureDirectoryExists($source);
            self::run(['git', 'init', '--quiet', $source], 'set up a git folder for Stockist');
        }
        // A local checkout is fetched by its absolute path, so a relative --stockist works too.
        $from = is_dir($repository) ? (string) realpath($repository) : $repository;
        self::run(['git', '-C', $source, 'fetch', '--quiet', '--depth=1', '--no-tags', $from, $ref], "fetch {$ref} from {$repository}", timeout: 300);

        return trim(self::run(['git', '-C', $source, 'rev-parse', 'FETCH_HEAD'], 'read the fetched commit')->output());
    }

    private static function install(string $workPath, string $commit, string $path, bool $offlineFonts): void
    {
        File::deleteDirectory($path);
        File::ensureDirectoryExists($path);
        $archive = "{$path}.tar";
        self::run(['git', '-C', "{$workPath}/source", 'archive', '--format=tar', "--output={$archive}", $commit], 'unpack the commit');
        self::run(['tar', '-xf', $archive, '-C', $path], 'unpack the commit');
        File::delete($archive);

        $env = ['COMPOSER_ALLOW_SUPERUSER' => '1', 'APP_ENV' => 'local'];
        self::run(['composer', 'install', '--no-interaction', '--no-progress', '--prefer-dist'], 'install its Composer packages', $path, $env, 900);
        self::run(['npm', 'ci', '--no-audit', '--no-fund'], 'install its npm packages', $path, $env, 900);

        if ($offlineFonts) {
            // Bunny Fonts can't be reached from here: answer the build's font requests from the
            // same families on npm (app/Pictures/node/offline-fonts.cjs). CI never does this.
            $families = self::bunnyFamilies((string) @file_get_contents("{$path}/vite.config.js"));
            if ($families !== []) {
                self::run(['npm', 'install', '--no-save', '--no-audit', '--no-fund', ...array_map(fn (string $family): string => '@fontsource/'.$family, $families)], 'install offline fonts', $path, $env, 300);
            }
            $env['NODE_OPTIONS'] = '--require '.app_path('Pictures/node/offline-fonts.cjs');
        }
        self::run(['npm', 'run', 'build'], 'build its assets'.($offlineFonts ? '' : ' (if fonts.bunny.net is unreachable here, set PICTURES_OFFLINE_FONTS=1)'), $path, $env, 900);

        File::put("{$path}/.pictures-installed", $commit."\n");
    }

    /**
     * The font families vite.config.js takes from Bunny Fonts, as @fontsource package names.
     *
     * @return list<string>
     */
    private static function bunnyFamilies(string $viteConfig): array
    {
        preg_match_all('/\bbunny\(\s*[\'"]([^\'"]+)[\'"]/', $viteConfig, $matches);

        return array_values(array_unique(array_map(fn (string $family): string => strtolower((string) preg_replace('/\s+/', '-', trim($family))), $matches[1])));
    }

    private function seed(): void
    {
        $seeding = "{$this->template}_seeding";
        $this->dropDatabase($seeding);
        $this->admin()->exec('CREATE DATABASE '.$this->quote($seeding));
        $env = $this->environment($seeding, 'http://127.0.0.1');

        self::run([PHP_BINARY, 'artisan', 'migrate:fresh', '--force', '--no-interaction'], 'migrate its database', $this->path, $env, 600);
        self::run([PHP_BINARY, 'artisan', 'db:seed', '--class=DevDataSeeder', '--force', '--no-interaction'], 'seed the Trentside demo', $this->path, $env, 1200);

        // Each Recipe starts signed out with nothing cached, whatever seeding left behind.
        $copy = $this->connect($seeding);
        $exists = $copy->prepare("select count(*) from pg_tables where schemaname = 'public' and tablename = ?");
        foreach (['sessions', 'cache', 'cache_locks'] as $table) {
            $exists->execute([$table]);
            if ((int) $exists->fetchColumn() > 0) {
                $copy->exec('TRUNCATE '.$this->quote($table));
            }
        }
        // The statement holds the connection open too; Postgres won't rename a database in use.
        unset($exists, $copy);

        // Renamed only once complete, so a seed that fails part way is never used.
        $this->admin()->exec('ALTER DATABASE '.$this->quote($seeding).' RENAME TO '.$this->quote($this->template));
    }

    /**
     * Stockist's settings while it runs for pictures. Every one is given outright, so nothing
     * from the shell or the help centre's own tests leaks in, and the same values go in its
     * .env so its artisan commands can be run by hand.
     *
     * @return array<string, string>
     */
    private function environment(string $database, string $url): array
    {
        $env = [
            'APP_NAME' => 'Stockist',
            'APP_ENV' => 'local',
            'APP_KEY' => self::APP_KEY,
            'APP_DEBUG' => 'false',
            'APP_URL' => $url,
            'APP_LOCALE' => 'en',
            'APP_FALLBACK_LOCALE' => 'en',
            'APP_MAINTENANCE_DRIVER' => 'file',
            'CLOCK_HELD_AT' => $this->moment->toIso8601String(),
            'DB_CONNECTION' => 'pgsql',
            'DB_URL' => '',
            'DB_HOST' => $this->postgres['host'],
            'DB_PORT' => (string) $this->postgres['port'],
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $this->postgres['username'],
            'DB_PASSWORD' => $this->postgres['password'],
            // In the database, so every fresh copy starts with no sessions and no sign-in
            // attempts counted against anyone.
            'SESSION_DRIVER' => 'database',
            'CACHE_STORE' => 'database',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'BROADCAST_CONNECTION' => 'log',
            'FILESYSTEM_DISK' => 'local',
            'SCOUT_DRIVER' => 'database',
            'LOG_CHANNEL' => 'single',
            'LOG_LEVEL' => 'warning',
            'DEMO_GUIDE_ENABLED' => 'false',
            'BOOST_ENABLED' => 'false',
            'BOOST_BROWSER_LOGS_WATCHER' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
        ];
        File::put("{$this->path}/.env", implode("\n", array_map(fn (string $key, string $value): string => "{$key}=\"{$value}\"", array_keys($env), $env))."\n");

        return $env;
    }

    private function waitUntilServing(InvokedProcess $server, string $url, string $log): void
    {
        $until = microtime(true) + 30;
        while (microtime(true) < $until) {
            if (! $server->running()) {
                throw new StockistUnavailable("Stockist's server stopped as it started: ".trim((string) @file_get_contents($log)));
            }
            try {
                if (Http::timeout(5)->get("{$url}/login")->successful()) {
                    return;
                }
            } catch (Throwable) {
                // Not listening yet.
            }
            usleep(200_000);
        }
        throw new StockistUnavailable("Stockist's server at {$url} didn't answer within 30 seconds.");
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new StockistUnavailable('No free port for Stockist.');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function databaseExists(string $database): bool
    {
        $query = $this->admin()->prepare('select 1 from pg_database where datname = ?');
        $query->execute([$database]);

        return $query->fetchColumn() !== false;
    }

    private function dropDatabase(string $database): void
    {
        $this->admin()->exec('DROP DATABASE IF EXISTS '.$this->quote($database).' WITH (FORCE)');
    }

    private function admin(): PDO
    {
        return $this->connect('postgres');
    }

    private function connect(string $database): PDO
    {
        return new PDO(
            "pgsql:host={$this->postgres['host']};port={$this->postgres['port']};dbname={$database}",
            $this->postgres['username'],
            $this->postgres['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private static function run(array $command, string $what, ?string $path = null, array $env = [], int $timeout = 120): ProcessResult
    {
        $result = Process::path($path ?? base_path())->env($env)->timeout($timeout)->run($command);
        if ($result->failed()) {
            $output = trim($result->errorOutput()."\n".$result->output());
            $tail = implode("\n", array_slice(explode("\n", $output), -15));

            throw new StockistUnavailable("Couldn't {$what} (".implode(' ', $command)."):\n{$tail}");
        }

        return $result;
    }
}
