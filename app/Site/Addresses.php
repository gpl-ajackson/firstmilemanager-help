<?php

namespace App\Site;

use App\Content\ProductAreaSource;
use App\Content\RolePath;

/**
 * Addresses of the site's pages other than Articles (ADR-0001 keeps each kind under its own
 * prefix). Lower case with hyphens, as spec #30's address table sets.
 */
final class Addresses
{
    /** A Product Area's listing, its name following the app's menu: "Inbound" is /areas/inbound. */
    public static function productArea(string $productArea): string
    {
        return ProductAreaSource::addressOf($productArea);
    }

    /** A Role Path's listing: /roles/owner-or-admin. */
    public static function rolePath(RolePath $role): string
    {
        return '/roles/'.$role->value;
    }
}
