<?php

namespace App\Site\Http\Controllers;

use App\Content\AudienceTier;
use App\Content\ContentLibrary;
use App\Content\RolePath;
use Illuminate\Contracts\View\View;

/**
 * The help home (/), in the Signboard layout (#14, layout A), top to bottom: search, the Role
 * Paths, the Getting-started Path, the Product Areas with their counts, For your clients and
 * the latest Release Notes (What's new), the ways to look something up, and "New to Stockist?"
 * (plan, "The home page").
 */
final class ShowHome
{
    /** How many of the latest Release Notes What's new shows on the home. */
    private const LATEST_RELEASE_NOTES = 3;

    public function __invoke(ContentLibrary $library): View
    {
        return view('site.home', [
            'roles' => RolePath::cases(),
            'path' => $library->gettingStartedPath(),
            'areas' => $library->productAreaCounts(),
            'clientArticles' => $library->clientArticles(),
            'hasClientGlossary' => $library->glossaryEntries(AudienceTier::Client)->isNotEmpty(),
            'releaseNotes' => $library->releaseNotes(self::LATEST_RELEASE_NOTES),
            'facts' => $library->facts(),
        ]);
    }
}
