<?php

namespace App\Site\Http\Controllers;

use App\Content\ContentLibrary;
use Illuminate\Http\RedirectResponse;

/**
 * /privacy: the help centre shares one privacy notice with the marketing site (spec #30, Further
 * Note 5), whose help-centre section says what the help centre records and how long it keeps it.
 * A temporary redirect, since the address follows the facts (content/facts.yaml).
 */
final class PrivacyNotice
{
    /** The heading anchor of the notice's help-centre section on the marketing site. */
    public const SECTION = '#the-help-centre';

    public function __invoke(ContentLibrary $library): RedirectResponse
    {
        $marketingSite = rtrim($library->facts()->url ?? 'https://firstmilemanager.co.uk', '/');

        return redirect()->away($marketingSite.'/privacy'.self::SECTION);
    }
}
