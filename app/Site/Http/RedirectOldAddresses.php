<?php

namespace App\Site\Http;

use App\Content\ContentLibrary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Addresses that last (ADR-0001): when an address has no page, any redirect the content keeps
 * for it is followed for good (301) before "page not found" is shown. One place for every
 * kind of old address: an Article's former address or Retired Article now; a renamed Glossary
 * Entry or Product Area later, by adding its redirects to the rebuild.
 *
 * The query string goes along; a browser carries the #Section Name over by itself.
 */
final class RedirectOldAddresses
{
    public function __invoke(NotFoundHttpException $notFound, Request $request): ?RedirectResponse
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return null;
        }

        $to = app(ContentLibrary::class)->redirectFor('/'.ltrim($request->path(), '/'));
        if ($to === null) {
            return null;
        }

        $query = $request->getQueryString();

        return redirect()->to($query === null ? $to : "{$to}?{$query}", 301);
    }
}
