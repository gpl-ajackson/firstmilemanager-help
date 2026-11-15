<?php

namespace App\Site\Http\Controllers;

use App\Pictures\PictureLibrary;
use App\Site\Asset;
use Symfony\Component\HttpFoundation\Response;

/**
 * /pictures/<fingerprint>/<file>: a sized Picture or drawn diagram the last rebuilds made,
 * cached for a year. The fingerprint is of what it was made from, so the address changes
 * whenever the Picture does.
 */
final class ServePicture
{
    public function __invoke(PictureLibrary $pictures, string $fingerprint, string $file): Response
    {
        $path = $pictures->servedFile($fingerprint, $file);
        abort_if($path === null, 404);

        return Asset::cacheForAYear(response()->file($path, ['Content-Type' => 'image/webp']));
    }
}
