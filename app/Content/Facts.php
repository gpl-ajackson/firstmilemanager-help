<?php

namespace App\Content;

use Illuminate\Database\Eloquent\Model;

/**
 * The help centre's facts in the database copy (one row, from content/facts.yaml): First Mile
 * Manager's name, description and stage as the marketing site states them, and the marketing
 * site's address. Read them through ContentLibrary::facts().
 *
 * @property string $name
 * @property string $description
 * @property string $stage "Early access" until alpha, when every page shows the early access banner.
 * @property string $url The marketing site, e.g. https://firstmilemanager.co.uk.
 */
class Facts extends Model
{
    public $timestamps = false;

    protected $table = 'facts';

    protected $guarded = [];

    /** The stage that shows the early access banner on every page, until alpha. */
    public const EARLY_ACCESS = 'Early access';

    /** A page of the marketing site, e.g. page('/early-access'). */
    public function page(string $path): string
    {
        return rtrim($this->url, '/').$path;
    }

    /** Whether First Mile Manager is still in early access, so every page shows the banner. */
    public function isEarlyAccess(): bool
    {
        return $this->stage === self::EARLY_ACCESS;
    }
}
