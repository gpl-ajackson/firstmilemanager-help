<?php

namespace App\Providers;

use App\Pictures\DiagramDrawer;
use App\Pictures\PictureLibrary;
use App\Site\Http\Controllers\ArticleFeedback;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Read when asked for, so a test's or a rebuild's own folders are used.
        $this->app->bind(PictureLibrary::class, fn (): PictureLibrary => new PictureLibrary(
            rtrim(Config::string('help.pictures_path'), '/'),
            rtrim(Config::string('help.served_pictures_path'), '/'),
            new DiagramDrawer(base_path('tools/diagrams/render.cjs'), public_path('fonts')),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('votes', ArticleFeedback::limit(...));

        // `php artisan optimize` (each deploy) works out the asset fingerprints once.
        $this->optimizes(optimize: 'help:cache-assets', clear: 'help:clear-assets', key: 'help assets');
    }
}
