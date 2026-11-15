<?php

namespace App\Content;

use Illuminate\Database\Eloquent\Model;

/**
 * The help centre's facts in the database copy (one row, from content/facts.yaml): Stockist's
 * name, description and stage as the marketing site states them, and the marketing site's
 * address. Read them through ContentLibrary::facts().
 *
 * @property string $name
 * @property string $description
 * @property string $stage "Early access", shown beside the logo.
 * @property string $url The marketing site, e.g. https://firstmilemanager.co.uk.
 */
class Facts extends Model
{
    public $timestamps = false;

    protected $table = 'facts';

    protected $guarded = [];

    /** A page of the marketing site, e.g. page('/pricing'). */
    public function page(string $path): string
    {
        return rtrim($this->url, '/').$path;
    }
}
