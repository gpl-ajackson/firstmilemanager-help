<?php

namespace App\Site\Http\Controllers;

use App\Site\Asset;
use Symfony\Component\HttpFoundation\Response;

/**
 * /assets/<fingerprint>/<path>: a style, font or image from public/, cached for a year. Only
 * the current fingerprint is served, so a cached copy is never the wrong file.
 */
final class ServeAsset
{
    public function __invoke(string $fingerprint, string $path): Response
    {
        abort_unless(Asset::fingerprint($path) === $fingerprint, 404);

        return Asset::cacheForAYear(response((string) Asset::contents($path), 200, [
            'Content-Type' => Asset::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))],
        ]));
    }
}
