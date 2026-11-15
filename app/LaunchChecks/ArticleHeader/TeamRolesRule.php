<?php

namespace App\LaunchChecks\ArticleHeader;

use App\LaunchChecks\ArticleFile;
use App\LaunchChecks\Findings;
use App\LaunchChecks\MainProject;

/**
 * team_roles: only Team Roles Stockist has as built, named as its screens name them; none at
 * all on a Client-tier Article, whose details never show Team Roles.
 */
final readonly class TeamRolesRule implements HeaderRule
{
    public function __construct(private MainProject $mainProject) {}

    public function field(): string
    {
        return 'team_roles';
    }

    public function check(ArticleFile $article, Findings $findings): void
    {
        if (! $article->has('team_roles')) {
            return;
        }
        if ($article->text('audience_tier') === 'Client') {
            $findings->fail('header.team_roles', $article->file, $article->lineOf('team_roles'), 'a Client-tier Article names no Team Roles in its details');

            return;
        }

        $roles = $article->header['team_roles'];
        if (! is_array($roles)) {
            return; // Its form is the content library's to check.
        }
        $existing = $this->mainProject->teamRoles();
        foreach ($roles as $role) {
            if (is_string($role) && ! in_array($role, $existing, true)) {
                $findings->fail('header.team_roles', $article->file, $article->lineOfItem('team_roles', $role),
                    "\"{$role}\" is not a Team Role in First Mile Manager; Team Roles that exist: ".($existing === [] ? 'none found in the main project\'s app/Domain/Access role enums' : implode(', ', $existing)));
            }
        }
    }
}
