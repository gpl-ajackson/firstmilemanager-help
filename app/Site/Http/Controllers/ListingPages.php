<?php

namespace App\Site\Http\Controllers;

use App\Content\ArticleType;
use App\Content\AudienceTier;
use App\Content\ContentLibrary;
use App\Content\RolePath;
use Illuminate\Contracts\View\View;

/**
 * The listings that gather Articles from every Product Area without copying them (plan,
 * "Listings"): Role Paths (/roles/<role>), the Getting-started Path (/getting-started), a
 * Product Area (/areas/<area>), For your clients (/for-your-clients) and Troubleshooting
 * (/troubleshooting). Only Published Articles appear. A Product Area without a Published
 * Article has no page, so its address is "page not found", after any redirect the content
 * keeps for a renamed area's old address is followed.
 */
final class ListingPages
{
    public function __construct(private readonly ContentLibrary $library) {}

    public function rolePath(string $role): View
    {
        $path = RolePath::tryFrom($role);
        abort_if($path === null, 404);

        return view('site.role-path', ['role' => $path, 'groups' => $this->library->rolePath($path)]);
    }

    public function gettingStarted(): View
    {
        return view('site.getting-started', ['path' => $this->library->gettingStartedPath()]);
    }

    public function productArea(string $name): View
    {
        $area = $this->library->shownProductArea($name);
        abort_if($area === null, 404);

        $byType = [];
        foreach ($this->library->articlesInArea($area) as $article) {
            $byType[$article->type->value][] = $article;
        }

        return view('site.product-area', ['area' => $area, 'byType' => $byType]);
    }

    public function forYourClients(): View
    {
        return view('site.for-your-clients', [
            'articles' => $this->library->clientArticles(),
            'entries' => $this->library->glossaryEntries(AudienceTier::Client),
        ]);
    }

    public function troubleshooting(): View
    {
        return view('site.troubleshooting', ['groups' => $this->library->troubleshootingArticles(), 'type' => ArticleType::Troubleshooting]);
    }
}
