<?php

namespace App\Site\Http\Controllers;

use App\Content\ContentLibrary;
use App\Preview\HelpPreview;
use App\Site\Addresses;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/**
 * /articles/<name>: a Published Article (in a Help Preview, a Draft too), or a Retired Article's
 * notice of what changed (ADR-0001).
 * Anything else is "page not found", after any redirect the content keeps for the address
 * (a former address, or a Retired Article with a replacement) is followed.
 */
final class ShowArticle
{
    public function __invoke(ContentLibrary $library, string $name): View|Response
    {
        // A Help Preview also shows the pull request's Draft Articles, marked as drafts.
        $article = $library->publishedArticle($name) ?? (HelpPreview::isOn() ? $library->draftArticle($name) : null);
        if ($article !== null) {
            return view('site.article', [
                'article' => $article,
                'related' => $library->relatedArticles($article),
                'placeInPath' => $library->placeInPath($article),
            ]);
        }

        $retired = $library->retiredArticle($name);
        abort_if($retired === null, 404);

        // 410 Gone with a page that helps: the Article is gone on purpose, its address still leads somewhere.
        return response()
            ->view('site.retired', [
                'article' => $retired,
                'productAreaAddress' => $library->productAreaIsShown($retired->product_area)
                    ? Addresses::productArea($retired->product_area)
                    : null,
            ], 410)
            ->header('X-Robots-Tag', 'noindex');
    }
}
