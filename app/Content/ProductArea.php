<?php

namespace App\Content;

use Illuminate\Database\Eloquent\Model;

/**
 * A Product Area listed in content/product-areas.yaml, with its place in the app's menu order.
 * Only the content library reads it, to put Product Areas in that order.
 *
 * @property string $name
 * @property int $position From 1, in the list's order.
 */
class ProductArea extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'name';

    protected $keyType = 'string';

    protected $guarded = [];
}
