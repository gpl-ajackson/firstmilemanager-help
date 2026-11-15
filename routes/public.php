<?php

use App\Site\Http\Controllers\ArticleFeedback;
use App\Site\Http\Controllers\GlossaryPages;
use App\Site\Http\Controllers\ListingPages;
use App\Site\Http\Controllers\LlmsTxt;
use App\Site\Http\Controllers\OpenSearchResult;
use App\Site\Http\Controllers\PrivacyNotice;
use App\Site\Http\Controllers\RobotsTxt;
use App\Site\Http\Controllers\ServeAsset;
use App\Site\Http\Controllers\ServePicture;
use App\Site\Http\Controllers\SettleSearch;
use App\Site\Http\Controllers\ShowArticle;
use App\Site\Http\Controllers\ShowHome;
use App\Site\Http\Controllers\ShowSearch;
use App\Site\Http\Controllers\ShowSuggestions;
use App\Site\Http\Controllers\Sitemap;
use App\Site\Http\Controllers\StillStuck;
use App\Site\Http\Controllers\WhatsNewPages;
use App\Site\Http\Middleware\SameOrigin;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public pages
|--------------------------------------------------------------------------
|
| Every page a reader can open. These routes run in the "public" middleware group, which has
| no session, cookie encryption or CSRF middleware, so no public response sets a cookie
| (spec #30, HTTP rules). Article Names and other addresses are lower case with hyphens.
|
*/

Route::get('/', ShowHome::class)->name('home');

Route::get('/articles/{name}', ShowArticle::class)
    ->where('name', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('articles.show');

// Listings: Articles gathered from every Product Area (plan, "Listings").
Route::get('/roles/{role}', [ListingPages::class, 'rolePath'])
    ->where('role', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('roles.show');
Route::get('/getting-started', [ListingPages::class, 'gettingStarted'])->name('getting-started');
Route::get('/areas/{name}', [ListingPages::class, 'productArea'])
    ->where('name', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('areas.show');
Route::get('/for-your-clients', [ListingPages::class, 'forYourClients'])->name('for-your-clients');
Route::get('/troubleshooting', [ListingPages::class, 'troubleshooting'])->name('troubleshooting');

Route::get('/glossary', [GlossaryPages::class, 'staff'])->name('glossary');
Route::get('/glossary/{name}', [GlossaryPages::class, 'staffEntry'])
    ->where('name', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('glossary.show');
Route::get('/client-glossary', [GlossaryPages::class, 'client'])->name('client-glossary');
Route::get('/client-glossary/{name}', [GlossaryPages::class, 'clientEntry'])
    ->where('name', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('client-glossary.show');

// What's new: the Release Notes, one per Stockist Release, at the release's date.
Route::get('/whats-new', [WhatsNewPages::class, 'index'])->name('whats-new');
Route::get('/whats-new/{date}', [WhatsNewPages::class, 'show'])
    ->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')
    ->name('whats-new.show');

// Results page, never indexed; the form works without script.
Route::get('/search', ShowSearch::class)->name('search');

// Indexing (spec #30, Addresses), from the database copy.
Route::get('/robots.txt', RobotsTxt::class)->name('robots');
Route::get('/sitemap.xml', Sitemap::class)->name('sitemap');
Route::get('/llms.txt', LlmsTxt::class)->name('llms');

// The privacy notice is shared with the marketing site; this sends readers to its help-centre section.
Route::get('/privacy', PrivacyNotice::class)->name('privacy');

// Fingerprinted, so cached for a year (App\Site\Asset, App\Pictures\PictureLibrary).
Route::get('/assets/{fingerprint}/{path}', ServeAsset::class)
    ->where('fingerprint', '[0-9a-f]{16}')
    ->where('path', '[A-Za-z0-9._/-]+')
    ->name('assets');

Route::get('/pictures/{fingerprint}/{file}', ServePicture::class)
    ->where('fingerprint', '[0-9a-f]{16}')
    ->where('file', '[a-z0-9-]+\.webp')
    ->name('pictures');

/*
| Not pages: addresses that record something and send the reader on, under their own prefixes
| (spec #30, Addresses), never indexed.
*/

Route::get('/still-stuck/{name}', StillStuck::class)
    ->where('name', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('still-stuck');

// The search service's own addresses (spec #30, "Search"). Suggestions as the reader types
// (#46), never recorded.
Route::get('/suggestions', ShowSuggestions::class)->name('suggestions');

// The script (#46) settles a Search here; the plain form settles it at /search. Only the help
// centre's own pages may post, so another site can't write Search Records.
Route::post('/searches', SettleSearch::class)->middleware(SameOrigin::class)->name('searches.settle');

// A result opened from a settled Search: marks the Search Opened, then sends the reader to the
// result. Glossary Entries' addresses are allowed for when search finds them (#45).
Route::get('/searches/{search_id}/opened/{place}/{to}', OpenSearchResult::class)
    ->whereUuid('search_id')
    ->where('place', '[1-9][0-9]{0,2}')
    ->where('to', '(?:articles|glossary|client-glossary)/[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('searches.opened');

// Article Feedback: plain form posts from an Article, checked for their origin instead of a CSRF
// token (no session). Votes are rate-limited by a key held in the cache for minutes ("votes").
Route::middleware(SameOrigin::class)->group(function (): void {
    Route::post('/feedback/{name}', [ArticleFeedback::class, 'vote'])
        ->where('name', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->middleware('throttle:votes')
        ->name('feedback.vote');

    Route::post('/feedback/{name}/reason', [ArticleFeedback::class, 'reason'])
        ->where('name', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('feedback.reason');
});
