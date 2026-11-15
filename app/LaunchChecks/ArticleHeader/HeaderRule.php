<?php

namespace App\LaunchChecks\ArticleHeader;

use App\LaunchChecks\ArticleFile;
use App\LaunchChecks\Findings;

/**
 * Check 1's rule for one header data field, beyond the form the content library already checks
 * (App\Content\ArticleSource): what needs the main project or the content folder's lists. A
 * ticket that adds a field checks its form in ArticleSource, and, when its rule needs either of
 * those, adds a HeaderRule here and lists it in ArticleHeaderCheck::rules().
 */
interface HeaderRule
{
    /** The header data field this rule is about (a key of the YAML front matter). */
    public function field(): string;

    public function check(ArticleFile $article, Findings $findings): void;
}
