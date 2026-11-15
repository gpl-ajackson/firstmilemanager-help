<?php

namespace App\Site\Http\Controllers;

use App\Content\AudienceTier;
use App\Content\ContentLibrary;
use App\Records\Records;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /still-stuck/<name>: a staff Article's "Still stuck?" link. It adds one to the Article's Still
 * Stuck Count for the day and opens an email to Stockist with the Article's title as the
 * subject, so the reader needn't explain where they were. A Client-tier Article has no such
 * link (clients go to their 3PL), so its name here is page not found, like any other.
 */
final class StillStuck
{
    public function __invoke(Request $request, ContentLibrary $library, Records $records, string $name): RedirectResponse
    {
        $article = $library->publishedArticle($name);
        abort_if($article === null || $article->audience_tier !== AudienceTier::Staff, 404);

        $records->countStillStuck($article->name, $request->userAgent());

        $email = config('help.still_stuck_email');
        assert(is_string($email));

        return redirect()->away("mailto:{$email}?subject=".rawurlencode($article->title))
            ->header('X-Robots-Tag', 'noindex');
    }
}
