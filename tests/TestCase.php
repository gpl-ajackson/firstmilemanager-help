<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\FakeStockist;
use Tests\Support\FixtureContent;
use Tests\Support\FixtureMainProject;
use Tests\Support\FixtureMarketingSite;
use Tests\Support\FixtureRecipes;
use Tests\Support\FixtureTemplates;

abstract class TestCase extends BaseTestCase
{
    /** Where this test's rebuilds leave the sized Pictures and diagrams the site serves. */
    private ?string $servedPictures = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Every test starts before launch, as the help centre was built; a test about going
        // public turns it on itself. config/help.php keeps the live setting.
        config(['help.going_public' => false]);

        $this->servedPictures = sys_get_temp_dir().'/help-served-pictures-'.getmypid().'-'.Str::lower(Str::random(12));
        config(['help.served_pictures_path' => $this->servedPictures]);
    }

    protected function tearDown(): void
    {
        FakeStockist::stopAll();
        FixtureContent::removeAll();
        FixtureMainProject::removeAll();
        FixtureMarketingSite::removeAll();
        FixtureTemplates::removeAll();
        FixtureRecipes::removeAll();
        if ($this->servedPictures !== null) {
            File::deleteDirectory($this->servedPictures);
        }

        parent::tearDown();
    }
}
