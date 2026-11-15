<?php

namespace App\Content;

use Illuminate\Database\Eloquent\Model;

/**
 * One old address in the database copy that redirects for good (ADR-0001), straight to where
 * its help is now. Only the content library writes it, from the content folder, on each rebuild.
 *
 * @property int $id
 * @property string $from_path The old address, such as /articles/receive-a-shipment.
 * @property string $to_path Where it leads now.
 * @property string $source_path The file that says so, relative to the content folder.
 */
class Redirect extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
