<?php

namespace App\LaunchChecks\ArticleHeader;

use App\LaunchChecks\ArticleFile;
use App\LaunchChecks\Findings;

/** owner: a person on the Article Owners list, never an agent. */
final readonly class OwnerRule implements HeaderRule
{
    public function __construct(private ArticleOwnerList $owners) {}

    public function field(): string
    {
        return 'owner';
    }

    public function check(ArticleFile $article, Findings $findings): void
    {
        $owner = $article->text('owner');
        if ($owner === null || $this->owners->has($owner)) {
            return;
        }

        $findings->fail('header.owner', $article->file, $article->lineOf('owner'), $this->owners->exists
            ? "\"{$owner}\" is not on the Article Owners list (".ArticleOwnerList::FILE.'); an Article Owner is a named Stockist person'
            : "\"{$owner}\" can't be checked: the content folder has no ".ArticleOwnerList::FILE.' (the GitHub logins of the people who own Articles)');
    }
}
