<?php

namespace App\LaunchChecks\PersonalDetails;

use App\LaunchChecks\MainProject;
use Symfony\Component\Finder\Finder;

/**
 * The email addresses, phone numbers and postcodes of the Trentside Logistics demo, as the
 * main project at the ref under test seeds them (database/seeders: Trentside's people, its
 * warehouse and its clients' contacts, all made up). Help may use these and no others.
 */
final class TrentsideDemoData
{
    /** Where the main project keeps the demo's seeders. */
    public const FOLDER = 'database/seeders';

    /** @return array<string, true> Each detail's key (PersonalDetail::key()). */
    public static function keysIn(MainProject $mainProject): array
    {
        $folder = $mainProject->path.'/'.self::FOLDER;
        if (! is_dir($folder)) {
            return [];
        }

        $keys = [];
        foreach (Finder::create()->files()->name('*.php')->in($folder) as $file) {
            foreach (PersonalDetails::in($file->getContents()) as $detail) {
                $keys[$detail->key()] = true;
            }
        }

        return $keys;
    }
}
