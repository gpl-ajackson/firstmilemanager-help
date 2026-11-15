<?php

namespace App\Site;

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Response;

/**
 * The styles, fonts and images in public/ at fingerprinted addresses,
 * /assets/<fingerprint>/<path>, so they are cached for a year (spec #30, HTTP rules) and still
 * change the moment a deploy changes them. No file sits at such an address, so the server hands
 * each request to the app (Http\Controllers\ServeAsset), which answers only for the current
 * fingerprint. A stylesheet's own links to files in public/ (tokens.css's fonts) are given
 * their fingerprinted addresses as it is served, so the brand kit's copy stays unchanged.
 */
final class Asset
{
    /** What may be served this way, with its type. */
    public const TYPES = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'woff2' => 'font/woff2',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
        'webp' => 'image/webp',
    ];

    private const A_YEAR = 31536000;

    /** @var array<string, string>|null Every file's fingerprint as help:cache-assets worked it out; null until read. */
    private static ?array $cached = null;

    public static function url(string $path): string
    {
        $path = ltrim($path, '/');
        $fingerprint = self::fingerprint($path);

        return $fingerprint === null ? '/'.$path : '/assets/'.$fingerprint.'/'.$path;
    }

    /** The fingerprint of a file in public/ as it is served, or null for one that can't be. */
    public static function fingerprint(string $path): ?string
    {
        self::$cached ??= self::readCache();
        if (isset(self::$cached[$path])) {
            return self::$cached[$path];
        }
        $contents = self::contents($path);

        return $contents === null ? null : substr(hash('xxh128', $contents), 0, 16);
    }

    /**
     * A file in public/ as it is served (a stylesheet with its links fingerprinted), or null
     * for anything that isn't a style, font or image there.
     */
    public static function contents(string $path): ?string
    {
        $file = self::file($path);
        if ($file === null) {
            return null;
        }
        $contents = (string) file_get_contents($file);
        if (pathinfo($file, PATHINFO_EXTENSION) !== 'css') {
            return $contents;
        }

        return (string) preg_replace_callback('/url\(\s*(["\']?)\/([^"\')?#]+)\1\s*\)/', function (array $link): string {
            return self::file($link[2]) === null ? $link[0] : 'url("'.self::url($link[2]).'")';
        }, $contents);
    }

    /**
     * Works out every servable file's fingerprint once (help:cache-assets, run by `php artisan
     * optimize` on each deploy) and keeps them in bootstrap/cache, so a page needn't read and hash
     * the styles, script and fonts on every request: PHP-FPM keeps no static between requests.
     * Gives back how many files it covers.
     */
    public static function cache(): int
    {
        self::forgetCache();
        $fingerprints = [];
        $public = (string) realpath(public_path());
        foreach (Finder::create()->files()->in($public)->sortByName() as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
            $fingerprint = self::fingerprint($path);
            if ($fingerprint !== null) {
                $fingerprints[$path] = $fingerprint;
            }
        }

        $partial = self::cachePath().'.'.bin2hex(random_bytes(4));
        File::put($partial, '<?php return '.var_export($fingerprints, true).';'.PHP_EOL);
        File::move($partial, self::cachePath());
        self::$cached = null;

        return count($fingerprints);
    }

    /** Removes the fingerprints help:cache-assets kept (help:clear-assets, `php artisan optimize:clear`). */
    public static function forgetCache(): void
    {
        File::delete(self::cachePath());
        self::$cached = null;
    }

    /** @return array<string, string> */
    private static function readCache(): array
    {
        $cached = is_file(self::cachePath()) ? require self::cachePath() : [];

        return is_array($cached) ? array_filter($cached, fn (mixed $fingerprint, mixed $path): bool => is_string($fingerprint) && is_string($path), ARRAY_FILTER_USE_BOTH) : [];
    }

    private static function cachePath(): string
    {
        return app()->bootstrapPath('cache/help-assets.php');
    }

    /** Lets Cloudflare and the browser keep a fingerprinted file for a year without asking again. */
    public static function cacheForAYear(Response $response): Response
    {
        $response->setPublic();
        $response->setMaxAge(self::A_YEAR);
        $response->setImmutable();

        return $response;
    }

    /** The real file in public/ at $path, when it is a style, font or image. */
    private static function file(string $path): ?string
    {
        $public = (string) realpath(public_path());
        $file = realpath($public.'/'.ltrim($path, '/'));
        if ($file === false || ! is_file($file) || ! str_starts_with($file, $public.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return array_key_exists(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::TYPES) ? $file : null;
    }
}
